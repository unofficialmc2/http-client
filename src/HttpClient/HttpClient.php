<?php
declare(strict_types=1);

namespace HttpClient;

use CurlHandle;
use CurlMultiHandle;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Class ApiHttpClient
 * @package Api\Utilities
 */
class HttpClient implements HttpClientInterface
{

    /** @var array<string, mixed> Tableaux de paramètre pour les different curl */
    protected array $curlsParam = [];
    /** @var \CurlMultiHandle|null Resource qui est donné par curl_multi_init() */
    protected ?CurlMultiHandle $curlMulHand = null;
    /** @var LoggerInterface|null */
    protected ?LoggerInterface $logger;
    /** @var array<string,CurlHandle> liste des curl init */
    protected array $curls = [];
    /** @var array<string,HttpResponse> tableaux de résultat des curls */
    protected array $curlResult;
    /** @var bool indique si le process est fini */
    protected bool $endOfProcess = false;
    /** @var bool flag pour savoir si on suit la redirection */
    private bool $followLocation = false;
    /** @var int durée du time out */
    private int $timeout = 30;

    /**
     * ApiHttpClient constructor.
     * @param \Psr\Log\LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function addParamRequest(string $url, array $headers = [], string $methode = HttpMethod::GET, string $data = ''): string
    {
        $clef = $this->getClef(8);
        $param = ["url" => $url, "headers" => $headers, "methode" => $methode, "data" => $data];
        $this->curlsParam[$clef] = $param;
        return $clef;
    }

    /**
     * @return void
     */
    public function clearParamRequestAndResult():void
    {
        $this->curlsParam = [];
        $this->curlResult = [];
        $this->curls = [];
    }

    /**
     * @param int $length
     * @return string
     */
    private function getClef(int $length): string
    {
        /** @noinspection CryptographicallySecureRandomnessInspection */
        $data = openssl_random_pseudo_bytes($length, $strong);
        if (false === $strong) {
            throw new RuntimeException("Un problème est survenu lors d'une génération cryptographique.");
        }
        return substr(bin2hex($data), $length);
    }

    /**
     * @inheritDoc
     */
    public function execAll(): void
    {
        $this->endOfProcess = false;
        $active = 0;
        $this->initAll();
        curl_multi_exec($this->curlMulHand, $active);
    }

    /**
     * @return bool
     */
    private function initAll(): bool
    {
        $this->curlMulHand = curl_multi_init();
        foreach ($this->curlsParam as $clef => $curlparam) {
            $curl = $this->initNewCurl($curlparam);
            if (!$curl) {
                $this->log('error', "Problème dans l'initialisation d'un curl", ["curlparam" => $curlparam]);
                return false;
            }
            $this->curls[$clef] = $curl;
            curl_multi_add_handle($this->curlMulHand, $curl);
        }
        return true;
    }

    /**
     * @param array<string,mixed> $curlparam
     * @return false|CurlHandle
     */
    private function initNewCurl(array $curlparam): CurlHandle|bool
    {
        $curl = curl_init($curlparam["url"]);
        if ($curl === false) {
            return false;
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->headerAdapter($curlparam["headers"]));
        $this->setCurlMethode($curlparam["methode"], $curl, $curlparam["data"]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, $this->followLocation);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
        if (isset($curlparam['ssl_verify']) && $curlparam['ssl_verify'] === false) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }

        return $curl;
    }

    /**
     * adapter les headers pour CUrl
     * @param array<string,mixed> $headers
     * @return array<int,string>
     */
    private function headerAdapter(array $headers): array
    {
        $curlHeaders = [];
        foreach ($headers as $key => $header) {
            $curlHeaders[] = sprintf("%s: %s", $key, (string)$header);
        }
        return $curlHeaders;
    }

    /**
     * @param string $methode
     * @param CurlHandle $curl
     * @param string $data
     */
    private function setCurlMethode(string $methode, CurlHandle $curl, string $data): void
    {
        switch ($methode) :
            case HttpMethod::GET:
                break;
            case HttpMethod::POST:
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                break;
            case HttpMethod::DELETE:
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $methode);
                break;
        endswitch;
    }

    /**
     * @param-stan 'error'|'debug' $type
     * @param string $type
     * @param string $message
     * @param array<string|int,string> $context
     * @return void
     */
    private function log(string $type, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            switch ($type) {
                case 'error':
                    $this->logger->error($message, $context);
                    break;
                case 'debug':
                    $this->logger->debug($message, $context);
                    break;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getResult(string $clef): HttpResponse
    {
        if (!$this->endOfProcess) {
            $this->waitResult();
        }
        if (!array_key_exists($clef, $this->curlResult)) {
            $this->log('error', "La clef curl n'éxiste pas", ["clef Curl" => $clef]);
            throw new RuntimeException("La clef curl n'éxiste pas");
        }
        return $this->curlResult[$clef];
    }

    /**
     * @inheritDoc
     */
    public function waitResult(): void
    {
        $active = null;
        do {
            $status = curl_multi_exec($this->curlMulHand, $active);
            if ($active) {
                curl_multi_select($this->curlMulHand);
            }
        } while ($active && $status === CURLM_OK);
        $result = [];
        //Verifier les erreurs
        if ($status !== CURLM_OK) {
            $this->log('error', "Error Curl", ["error" => curl_multi_strerror($status)]);
            throw new RuntimeException("Une erreur a eu lieu avec le serveur");
        }
        foreach ($this->curls as $clef => $curl) {
            /** @var string|null $response */
            $response = curl_multi_getcontent($curl);
            $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            $status = curl_errno($curl);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $result[$clef] = $this->makeResponse($status, $response, $code, $error, $headerSize);
            curl_multi_remove_handle($this->curlMulHand, $curl);
        }
        $this->curlResult = $result;
        $this->endOfProcess = true;
        $this->closeMultiCurl();
    }

    /**
     * @param int $status
     * @param bool|string|null $response
     * @param mixed $code
     * @param string $error
     * @param int $headerSize
     * @return HttpResponse
     */
    private function makeResponse(
        int $status,
        bool|string|null $response,
        mixed $code,
        string $error,
        int $headerSize): HttpResponse
    {
        if ($status !== CURLE_OK) {
            switch ($status) {
                case CURLE_OPERATION_TIMEOUTED:
                    $this->log('notice', "Curl n'a pas reçu de réponse dans les temps");
                    return new HttpResponse(504, [], 'Temps d’attente de la réponse écoulé');
                default:
                    $this->log('error', "Error Curl", ["error" => curl_strerror($status)]);
                    throw new RuntimeException("Une erreur a eu lieu avec le serveur");
            }
        }
        if (!is_string($response)) {
            $this->log('error', "Curl error", ["error" => $error]);
            return new HttpResponse(false, [], $error);
        }
        $body = substr($response, $headerSize);
        $splitedRep = preg_split("/\r\n\r\n|\n\n/", $response);
        $headers = $this->headerParse($splitedRep[0] ?? '');
        return new HttpResponse($code, $headers, $body);
    }

    /**
     * @param string $rawHeaders
     * @return array<string, string>
     */
    private function headerParse(string $rawHeaders): array
    {
        if (empty($rawHeaders)) {
            return [];
        }
        $headers = [];
        $key = '';
        foreach (explode("\n", $rawHeaders) as $i => $h) {
            $h = explode(':', $h, 2);
            if (isset($h[1])) {
                if (!isset($headers[$h[0]])) {
                    $headers[$h[0]] = trim($h[1]);
                } elseif (is_array($headers[$h[0]])) {
                    $headers[$h[0]] = array_merge($headers[$h[0]], [trim($h[1])]);
                } else {
                    $headers[$h[0]] = array_merge([$headers[$h[0]]], [trim($h[1])]);
                }
                $key = strtolower($h[0]);
            } else {
                if ($h[0][0] === "\t") {
                    $headers[$key] .= "\r\n\t" . trim($h[0]);
                } elseif (!$key) {
                    $headers[0] = trim($h[0]);
                }
            }
        }
        return $headers;
    }

    /**
     *
     */
    private function closeMultiCurl(): void
    {
        if (null !== $this->curlMulHand) {
            curl_multi_close($this->curlMulHand);
            $this->curlMulHand = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function curlUnique(string $url, array $headers = [], string $methode = HttpMethod::GET, string $data = '', bool $ssl_verify=true): HttpResponse
    {
        $curl = $this->initNewCurl([
            "url" => $url,
            "headers" => $headers,
            "methode" => $methode,
            "data" => $data,
            "post" => $methode === HttpMethod::POST,
            "ssl_verify" => $ssl_verify
        ]);
        if (!$curl) {
            throw new RuntimeException("Erreur de curl ");
        }
// Vérifie les erreurs et affiche le message d'erreur
        $curlResult = curl_exec($curl);
        $status = curl_errno($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $response = $this->makeResponse($status, $curlResult, $code, $error, $headerSize);
        curl_close($curl);
        return $response;
    }

    /**
     *
     */
    public function followRedirect(): void
    {
        $this->followLocation = true;
    }

    /**
     * @inheritDoc
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }
}
