<?php

namespace Rstacode\Otpiq\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase;
use Rstacode\Otpiq\Exceptions\OtpiqApiException;
use Rstacode\Otpiq\OtpiqService;
use Rstacode\Otpiq\OtpiqServiceProvider;

class OtpiqServiceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [OtpiqServiceProvider::class];
    }

    private function createServiceWithMockClient(MockHandler $mock, array &$history = []): OtpiqService
    {
        $handlerStack = HandlerStack::create($mock);

        if (func_num_args() > 1) {
            $handlerStack->push(Middleware::history($history));
        }

        $client = new Client(['handler' => $handlerStack]);

        $service = new OtpiqService('test-api-key', 'https://api.otpiq.com/api/');

        $reflection = new \ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $client);

        return $service;
    }

    public function test_constructor_sets_api_key(): void
    {
        $service = new OtpiqService('my-api-key', 'https://api.otpiq.com/api/');

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('apiKey');
        $property->setAccessible(true);

        $this->assertEquals('my-api-key', $property->getValue($service));
    }

    public function test_constructor_normalizes_base_url_with_trailing_slash(): void
    {
        $service = new OtpiqService('key', 'https://api.otpiq.com/api');

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('baseUrl');
        $property->setAccessible(true);

        $this->assertEquals('https://api.otpiq.com/api/', $property->getValue($service));
    }

    public function test_constructor_keeps_trailing_slash(): void
    {
        $service = new OtpiqService('key', 'https://api.otpiq.com/api/');

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('baseUrl');
        $property->setAccessible(true);

        $this->assertEquals('https://api.otpiq.com/api/', $property->getValue($service));
    }

    public function test_get_project_info_returns_array(): void
    {
        $responseData = [
            'projectName' => 'Test Project',
            'credit' => 15000,
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $service = $this->createServiceWithMockClient($mock);
        $result = $service->getProjectInfo();

        $this->assertEquals($responseData, $result);
    }

    public function test_send_sms_with_verification_data(): void
    {
        $responseData = [
            'message' => 'SMS task created successfully',
            'smsId' => 'sms-123456',
            'remainingCredit' => 14800,
            'cost' => 200,
            'canCover' => true,
            'paymentType' => 'prepaid',
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $service = $this->createServiceWithMockClient($mock);
        $result = $service->sendSms([
            'phoneNumber' => '964750123456',
            'smsType' => 'verification',
            'verificationCode' => '123456',
            'provider' => 'whatsapp-sms',
        ]);

        $this->assertEquals($responseData, $result);
        $this->assertEquals('sms-123456', $result['smsId']);
    }

    public function test_send_sms_with_custom_message(): void
    {
        $responseData = [
            'message' => 'SMS task created successfully',
            'smsId' => 'sms-789',
            'remainingCredit' => 14600,
            'cost' => 200,
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $service = $this->createServiceWithMockClient($mock);
        $result = $service->sendSms([
            'phoneNumber' => '964750123456',
            'smsType' => 'custom',
            'customMessage' => 'Your order has been confirmed.',
            'senderId' => 'MyShop',
            'provider' => 'sms',
        ]);

        $this->assertEquals('sms-789', $result['smsId']);
    }

    public function test_get_sender_ids_returns_array(): void
    {
        $responseData = [
            'senderIds' => [
                ['id' => '1', 'name' => 'MyBrand', 'status' => 'approved'],
                ['id' => '2', 'name' => 'TestSender', 'status' => 'pending'],
            ],
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $service = $this->createServiceWithMockClient($mock);
        $result = $service->getSenderIds();

        $this->assertEquals($responseData, $result);
        $this->assertCount(2, $result['senderIds']);
    }

    public function test_track_sms_returns_status(): void
    {
        $responseData = [
            'smsId' => 'sms-123456',
            'phoneNumber' => '964750123456',
            'status' => 'delivered',
            'cost' => 200,
            'isFinalStatus' => true,
            'lastChannel' => 'whatsapp',
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $service = $this->createServiceWithMockClient($mock);
        $result = $service->trackSms('sms-123456');

        $this->assertEquals('delivered', $result['status']);
        $this->assertTrue($result['isFinalStatus']);
    }

    public function test_request_throws_otpiq_api_exception_on_client_error(): void
    {
        $responseBody = json_encode(['message' => 'Unauthorized']);
        $mock = new MockHandler([
            new \GuzzleHttp\Exception\ClientException(
                'Client error',
                new Request('GET', 'info'),
                new Response(401, [], $responseBody)
            ),
        ]);

        $service = $this->createServiceWithMockClient($mock);

        $this->expectException(OtpiqApiException::class);
        $this->expectExceptionMessage('Unauthorized');

        $service->getProjectInfo();
    }

    public function test_request_throws_otpiq_api_exception_on_connection_error(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'info')),
        ]);

        $service = $this->createServiceWithMockClient($mock);

        $this->expectException(OtpiqApiException::class);
        $this->expectExceptionMessage('Connection refused');

        $service->getProjectInfo();
    }

    public function test_request_returns_empty_array_on_null_json(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'null'),
        ]);

        $service = $this->createServiceWithMockClient($mock);
        $result = $service->getProjectInfo();

        $this->assertEquals([], $result);
    }

    public function test_send_sms_sends_post_request_with_json_body(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['smsId' => 'sms-test'])),
        ]);

        $history = [];
        $service = $this->createServiceWithMockClient($mock, $history);

        $service->sendSms(['phoneNumber' => '964750123456', 'smsType' => 'verification']);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertStringContainsString('sms', (string) $request->getUri());

        $body = json_decode($request->getBody()->getContents(), true);
        $this->assertEquals('964750123456', $body['phoneNumber']);
    }

    public function test_get_project_info_sends_get_request(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['projectName' => 'Test'])),
        ]);

        $history = [];
        $service = $this->createServiceWithMockClient($mock, $history);

        $service->getProjectInfo();

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertStringContainsString('info', (string) $request->getUri());
    }

    public function test_track_sms_includes_sms_id_in_url(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['status' => 'pending'])),
        ]);

        $history = [];
        $service = $this->createServiceWithMockClient($mock, $history);

        $service->trackSms('sms-abc123');

        $request = $history[0]['request'];
        $this->assertStringContainsString('sms/track/sms-abc123', (string) $request->getUri());
    }

    public function test_request_throws_on_server_error(): void
    {
        $responseBody = json_encode(['error' => 'Internal server error']);
        $mock = new MockHandler([
            new \GuzzleHttp\Exception\ServerException(
                'Server error',
                new Request('GET', 'info'),
                new Response(500, [], $responseBody)
            ),
        ]);

        $service = $this->createServiceWithMockClient($mock);

        $this->expectException(OtpiqApiException::class);
        $service->getProjectInfo();
    }

    public function test_get_sender_ids_sends_get_request(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['senderIds' => []])),
        ]);

        $history = [];
        $service = $this->createServiceWithMockClient($mock, $history);

        $service->getSenderIds();

        $request = $history[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertStringContainsString('sender-ids', (string) $request->getUri());
    }

    public function test_send_sms_does_not_include_json_body_when_data_is_empty(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['smsId' => 'sms-empty'])),
        ]);

        $history = [];
        $service = $this->createServiceWithMockClient($mock, $history);

        $service->sendSms([]);

        $request = $history[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $body = $request->getBody()->getContents();
        $this->assertEmpty($body);
    }
}
