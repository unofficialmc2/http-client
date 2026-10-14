<?php
declare(strict_types=1);

namespace Test;

use HttpClient\HttpMethod;
use HttpClient\HttpResponse;
use HttpClientStub\HttpClient;
use JsonException;
use RuntimeException;

/**
 * Class ApiHttpClientStubTest
 * @package Test
 */
class HttpClientStubTest extends TestCase
{

    /**
     * @throws \JsonException
     */
    public function testAddParamRequest(): void
    {
        $stub = new HttpClient();
        $stub->addResult(false, [], json_encode(
            [
                "test" => "c'est moi qui fait",
                "testBis" => "c'est plus moi"
            ],
            JSON_THROW_ON_ERROR
        ));
        $clef = $stub->addParamRequest("", [], HttpMethod::GET);
        self::assertEquals(8, strlen($clef));
    }

    /**
     * @throws JsonException
     */
    public function testAddResult(): void
    {
        $stub = new HttpClient();
        $stub->addResult(200, [], json_encode(
            [
                "test" => "c'est moi qui fait",
                "testBis" => "c'est plus moi"
            ],
            JSON_THROW_ON_ERROR
        ));
        $clef = $stub->addParamRequest("", [], HttpMethod::GET);
        $result = $stub->getResult($clef);
        self::assertInstanceOf(HttpResponse::class, $result);
        self::assertTrue($result->isSuccess());
        self::assertEquals(
            [
                "test" => "c'est moi qui fait",
                "testBis" => "c'est plus moi"
            ],
            $result->getData()
        );
        $stub->addResult(false, [], json_encode(
            [
                "test" => "ok c'est une erreur "
            ],
            JSON_THROW_ON_ERROR
        ));
        $clef = $stub->addParamRequest("", [], HttpMethod::GET);
        $result = $stub->getResult($clef);
        self::assertInstanceOf(HttpResponse::class, $result);
        self::assertFalse($result->isSuccess());
        self::assertEquals(
            [
                "test" => "ok c'est une erreur "
            ],
            $result->getData()
        );
    }

    /**
     * @throws JsonException
     */
    public function testResetResult(): void
    {
        $this->expectException(RuntimeException::class);
        $stub = new HttpClient();
        $stub->addResult(200, [], json_encode(
            [
                "test" => "c'est moi qui fait",
                "testBis" => "c'est plus moi"
            ],
            JSON_THROW_ON_ERROR
        ));
        $stub->resetResult();
        $stub->addParamRequest("", [], HttpMethod::GET);
    }

    /**
     * @throws JsonException
     */
    public function testCurlUnique(): void
    {
        $stub = new HttpClient();
        $stub->addResult(200, [], json_encode(
            [
                "test" => "c'est moi qui fait",
                "testBis" => "c'est plus moi"
            ],
            JSON_THROW_ON_ERROR
        ));
        $result = $stub->curlUnique("", [], HttpMethod::GET);
        self::assertInstanceOf(HttpResponse::class, $result);
        self::assertTrue($result->isSuccess());
        self::assertEquals(
            [
                "test" => "c'est moi qui fait",
                "testBis" => "c'est plus moi"
            ],
            $result->getData()
        );
        $stub->addResult(false, [], json_encode(
            [
                "test" => "ok c'est une erreur "
            ],
            JSON_THROW_ON_ERROR
        ));
        $result = $stub->curlUnique("", [], HttpMethod::GET);
        self::assertInstanceOf(HttpResponse::class, $result);
        self::assertFalse($result->isSuccess());
        self::assertEquals(
            [
                "test" => "ok c'est une erreur "
            ],
            $result->getData()
        );
    }
}
