<?php

namespace Esia;

use Esia\Exceptions\AbstractEsiaException;
use Esia\Exceptions\ForbiddenException;
use Esia\Exceptions\RequestFailException;
use Esia\Http\GuzzleHttpClient;
use Esia\Signer\Exceptions\CannotGenerateRandomIntException;
use Esia\Signer\Exceptions\SignFailException;
use Esia\Signer\SignerInterface;
use Esia\Signer\SignerPKCS7;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Class OpenId
 */
class OpenId
{
    use LoggerAwareTrait;

    /**
     * @var SignerInterface
     */
    private $signer;

    /**
     * Http Client
     *
     * @var ClientInterface
     */
    private $client;

    /**
     * Config
     *
     * @var Config
     */
    private $config;
    protected $clientCertHash = null;

    public function __construct(Config $config, ClientInterface $client = null)
    {
        $this->config = $config;
        $this->client = $client ?? new GuzzleHttpClient(new Client());
        $this->logger = new NullLogger();
        $this->signer = new SignerPKCS7(
            $config->getCertPath(),
            $config->getPrivateKeyPath(),
            $config->getPrivateKeyPassword(),
            $config->getTmpPath()
        );
    }

    /**
     * Replace default signer
     */
    public function setSigner(SignerInterface $signer): void
    {
        $this->signer = $signer;
    }

    /**
     * Get config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Return an url for authentication
     *
     * ```php
     *     <a href="<?=$esia->buildUrl()?>">Login</a>
     * ```
     *
     * @return string|false
     * @throws SignFailException
     */
    public function buildUrl()
    {
        $timestamp = $this->getTimeStamp();
        $state = $this->buildState();
        $message = $this->config->getScopeString()
            . $timestamp
            . $this->config->getClientId()
            . $state;

        $clientSecret = $this->signer->sign($message);

        $url = $this->config->getCodeUrl() . '?%s';

        $params = [
            'client_id' => $this->config->getClientId(),
            'client_secret' => $clientSecret,
            'redirect_uri' => $this->config->getRedirectUrl(),
            'scope' => $this->config->getScopeString(),
            'response_type' => $this->config->getResponseType(),
            'state' => $state,
            'access_type' => $this->config->getAccessType(),
            'timestamp' => $timestamp,
        ];

        $request = http_build_query($params);

        return sprintf($url, $request);
    }

    public function buildUrl_V2(string $hash)
    {
     $timestamp = $this->getTimeStamp();
     $state = $this->buildState();
     // собираем client_secret по новым правилам
     $message =  $this->config->getClientId()
          . $this->config->getScopeString()
          . $timestamp         
          . $state         
          . $this->config->getRedirectUrl();    
     // используем алгоритм ГОСТ2012 для подписания     
     $this->signer = new SignerCPDataHash(
          $this->config->getCertPath(),           // для КриптоПро эти
          $this->config->getPrivateKeyPath(),     // параметры не нужны
          $this->config->getPrivateKeyPassword(), // потому что сертификат и ключ
          $this->config->getTmpPath()             // импортированы в хранилище
     );
     $this->signer->setConfig($this->config);
     // $this->signer->setHash($hash);
     $clientSecret = $this->signer->sign($message);
     $url = $this->config->getCodeUrl_V2() . '?%s';
     $params = [
         'client_id' => $this->config->getClientId(),
         'client_secret' => $clientSecret,
         'redirect_uri' => $this->config->getRedirectUrl(),
         'scope' => $this->config->getScopeString(),
         'response_type' => $this->config->getResponseType(),
         'state' => $state,
         'access_type' => $this->config->getAccessType(),
         'timestamp' => $timestamp,
         'client_certificate_hash' => $this->clientCertHash, // ГОСТ-хэш нашего сертификата     
     ];
     $request = http_build_query($params);      
     return sprintf($url, $request); 
    }

    /**
     * Return an url for logout
     */
    public function buildLogoutUrl(string $redirectUrl = null): string
    {
        $url = $this->config->getLogoutUrl() . '?%s';
        $params = [
            'client_id' => $this->config->getClientId(),
        ];

        if ($redirectUrl) {
            $params['redirect_url'] = $redirectUrl;
        }

        $request = http_build_query($params);

        return sprintf($url, $request);
    }

    /**
     * Method collect a token with given code
     *
     * @throws SignFailException
     * @throws AbstractEsiaException
     */
    public function getToken(string $code): string
    {
        $timestamp = $this->getTimeStamp();
        $state = $this->buildState();

        $clientSecret = $this->signer->sign(
            $this->config->getScopeString()
            . $timestamp
            . $this->config->getClientId()
            . $state
        );

        $body = [
            'client_id' => $this->config->getClientId(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_secret' => $clientSecret,
            'state' => $state,
            'redirect_uri' => $this->config->getRedirectUrl(),
            'scope' => $this->config->getScopeString(),
            'timestamp' => $timestamp,
            'token_type' => 'Bearer',
            'refresh_token' => $state,
        ];

        $payload = $this->sendRequest(
            new Request(
                'POST',
                $this->config->getTokenUrl(),
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query($body)
            )
        );

        $this->logger->debug('Payload: ', $payload);

        $token = $payload['access_token'];
        $this->config->setToken($token);

        # get object id from token
        $chunks = explode('.', $token);
        $payload = json_decode($this->base64UrlSafeDecode($chunks[1]), true);
        $this->config->setOid($payload['urn:esia:sbj_id']);

        return $token;
    }

    public function getToken_V3(string $code): string
    {
        $timestamp = $this->getTimeStamp();
        $state = $this->buildState();

        $this->signer = new SignerCPDataHash(
            $this->config->getCertPath(),
            $this->config->getPrivateKeyPath(),
            $this->config->getPrivateKeyPassword(),
            $this->config->getTmpPath()
        );

        $clientSecret = $this->signer->sign(
              $this->config->getClientId()
            . $this->config->getScopeString()
            . $timestamp
            . $state
            . $this->config->getRedirectUrl()
        );

        $body = [
            'client_id' => $this->config->getClientId(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_secret' => $clientSecret,
            'state' => $state,
            'redirect_uri' => $this->config->getRedirectUrl(),
            'scope' => $this->config->getScopeString(),
            'timestamp' => $timestamp,
            'token_type' => 'Bearer',
            'refresh_token' => $state,
            'client_certificate_hash' => $this->clientCertHash,
        ];

        $payload = $this->sendRequest(
            new Request(
                'POST',
                $this->config->getTokenUrl_V3(),
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query($body)
            )
        );

        $this->logger->debug('Payload: ', $payload);

        $token = $payload['access_token'];

        $chunks = explode('.', $token);
        $payload = json_decode($this->base64UrlSafeDecode($chunks[1]), true);
        $header = json_decode($this->base64UrlSafeDecode($chunks[0]), true);
        $_token_signature  = $this->base64UrlSafeDecode($chunks[2]);

        if ('JWT'==$header->typ) {
            $store = new \CPStore();            
            $store->Open(CURRENT_USER_STORE, "Users", STORE_OPEN_READ_ONLY); // используем хранилище Users
            $certs = $store->get_Certificates();
            $certlist = $certs->Find(CERTIFICATE_FIND_SHA1_HASH, $this->ESIACertSHA1, 0); // ищем в хранилище сертификат ЕСИА по его sha1 хэшу
            $cert = $certlist->Item(1);
            if (!$cert) {
                 throw new CannotReadCertificateException('Cannot read the certificate');
            }              
            
            $hd = new \CPHashedData();        
            $hd->set_DataEncoding(BASE64_TO_BINARY);
            switch ($header->alg) {
               case 'GOST3410_2012_256':
                   $hd->set_Algorithm(CADESCOM_HASH_ALGORITHM_CP_GOST_3411_2012_256);
                   break; 
               case 'GOST3410_2012_512':
                   $hd->set_Algorithm(CADESCOM_HASH_ALGORITHM_CP_GOST_3411_2012_512);
                   break;             
               default:
                   throw new Exception('Invalid signature algorithm');    
            }
            $hd->Hash(base64_encode($chunks[0].'.'.$chunks[1]));        
            $rs = new \CPRawSignature();
            $rs->VerifyHash($hd, bin2hex(strrev($_token_signature)), $cert);

            //если попали на эту строчку, значит подпись валидная. Иначе бы уже было вызвано исключение.
            $this->config->setOid($payload['urn:esia:sbj_id']);  
            $this->config->setToken($token);
        } // JWT token

        return $token;
    }

    /**
     * Fetch person info from current person
     *
     * You must collect token person before
     * calling this method
     *
     * @throws AbstractEsiaException
     */
    public function getPersonInfo(): array
    {
        $url = $this->config->getPersonUrl();

        return $this->sendRequest(new Request('GET', $url));
    }

    /**
     * Fetch contact info about current person
     *
     * You must collect token person before
     * calling this method
     *
     * @throws Exceptions\InvalidConfigurationException
     * @throws AbstractEsiaException
     */
    public function getContactInfo(): array
    {
        $url = $this->config->getPersonUrl() . '/ctts';
        $payload = $this->sendRequest(new Request('GET', $url));

        if ($payload && $payload['size'] > 0) {
            return $this->collectArrayElements($payload['elements']);
        }

        return $payload;
    }


    /**
     * Fetch address from current person
     *
     * You must collect token person before
     * calling this method
     *
     * @throws Exceptions\InvalidConfigurationException
     * @throws AbstractEsiaException
     */
    public function getAddressInfo(): array
    {
        $url = $this->config->getPersonUrl() . '/addrs';
        $payload = $this->sendRequest(new Request('GET', $url));

        if ($payload['size'] > 0) {
            return $this->collectArrayElements($payload['elements']);
        }

        return $payload;
    }

    /**
     * Fetch documents info about current person
     *
     * You must collect token person before
     * calling this method
     *
     * @throws Exceptions\InvalidConfigurationException
     * @throws AbstractEsiaException
     */
    public function getDocInfo(): array
    {
        $url = $this->config->getPersonUrl() . '/docs';

        $payload = $this->sendRequest(new Request('GET', $url));

        if ($payload && $payload['size'] > 0) {
            return $this->collectArrayElements($payload['elements']);
        }

        return $payload;
    }

    /**
     * This method can iterate on each element
     * and fetch entities from esia by url
     *
     * @throws AbstractEsiaException
     */
    private function collectArrayElements($elements): array
    {
        $result = [];
        foreach ($elements as $elementUrl) {
            $elementPayload = $this->sendRequest(new Request('GET', $elementUrl));

            if ($elementPayload) {
                $result[] = $elementPayload;
            }
        }

        return $result;
    }

    /**
     * @throws AbstractEsiaException
     */
    private function sendRequest(RequestInterface $request): array
    {
        try {
            if ($this->config->getToken()) {
                /** @noinspection CallableParameterUseCaseInTypeContextInspection */
                $request = $request->withHeader('Authorization', 'Bearer ' . $this->config->getToken());
            }
            $response = $this->client->sendRequest($request);
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (!is_array($responseBody)) {
                throw new RuntimeException(
                    sprintf(
                        'Cannot decode response body. JSON error (%d): %s',
                        json_last_error(),
                        json_last_error_msg()
                    )
                );
            }

            return $responseBody;
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('Request was failed', ['exception' => $e]);
            $prev = $e->getPrevious();

            // Only for Guzzle
            if ($prev instanceof BadResponseException
                && $prev->getResponse() !== null
                && $prev->getResponse()->getStatusCode() === 403
            ) {
                throw new ForbiddenException('Request is forbidden', 0, $e);
            }

            throw new RequestFailException('Request is failed', 0, $e);
        } catch (RuntimeException $e) {
            $this->logger->error('Cannot read body', ['exception' => $e]);
            throw new RequestFailException('Cannot read body', 0, $e);
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Wrong header', ['exception' => $e]);
            throw new RequestFailException('Wrong header', 0, $e);
        }
    }

    private function getTimeStamp(): string
    {
        return date('Y.m.d H:i:s O');
    }

    /**
     * Generate state with uuid
     *
     * @throws SignFailException
     */
    private function buildState(): string
    {
        try {
            return sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0x0fff) | 0x4000,
                random_int(0, 0x3fff) | 0x8000,
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0xffff)
            );
        } catch (Exception $e) {
            throw new CannotGenerateRandomIntException('Cannot generate random integer', $e);
        }
    }

    /**
     * Url safe for base64
     */
    private function base64UrlSafeDecode(string $string): string
    {
        $base64 = strtr($string, '-_', '+/');

        return base64_decode($base64);
    }
}
