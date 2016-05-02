<?php

namespace LaravelMns\Test;

use AliyunMNS\Requests\SendMessageRequest;
use AliyunMNS\Responses\ReceiveMessageResponse;
use AliyunMNS\Responses\SendMessageResponse;
use Carbon\Carbon;
use LaravelMns\MnsAdapter;
use LaravelMns\MnsQueue;
use Mockery as m;

class MnsQueueTest extends AbstractTestCase
{
    public function tearDown()
    {
        parent::tearDown();
    }

    public function setUp()
    {
        parent::setUp();
        
        $this->default = 'default';
        $this->delay = 100;
        
        $this->mockedJob = 'job';
        $this->mockedData = ['data'];
        $this->mockedPayload = json_encode([
            'job'  => $this->mockedJob,
            'data' => $this->mockedData,
        ]);
        $this->mnsClient = m::mock(\AliyunMNS\Client::class);
        $this->queueClient = m::mock(\AliyunMNS\Queue::class);
        $this->request = new SendMessageRequest($this->mockedPayload);
        $this->response = m::mock(SendMessageResponse::class);
        $this->mnsClient
            ->shouldReceive('getQueueRef')
            ->with($this->default)
            ->andReturn($this->queueClient);
        $this->queueClient
            ->shouldReceive('getQueueName')
            ->once()
            ->andReturn($this->default);
    }

    public function testPushProperlyPushesJobsOntoMns()
    {
        $mnsAdapter = new MnsAdapter($this->mnsClient, $this->default);
        $mnsQueue = m::mock(MnsQueue::class . '[createPayload]', [$mnsAdapter])
                     ->shouldAllowMockingProtectedMethods();
        $mnsQueue->shouldReceive('createPayload')
                 ->once()
                 ->with($this->mockedJob, $this->mockedData)
                 ->andReturn($this->mockedPayload);

        $this->queueClient
            ->shouldReceive('sendMessage')
            ->once()
            ->with(m::type(SendMessageRequest::class))
            ->andReturn($this->response);
        $this->response
            ->shouldReceive('getMessageId')
            ->withNoArgs()
            ->andReturn(123);
        $id = $mnsQueue->push($this->mockedJob, $this->mockedData, $this->default);
        $this->assertEquals(123, $id);
    }

    public function testLaterProperlyPushesDelayedOntoMns()
    {
        $mnsAdapter = new MnsAdapter($this->mnsClient, $this->default);
        $mnsQueue = m::mock(MnsQueue::class . '[createPayload, getSeconds]', [$mnsAdapter])
                     ->shouldAllowMockingProtectedMethods();
        $mnsQueue->shouldReceive('createPayload')
                 ->once()
                 ->with($this->mockedJob, $this->mockedData)
                 ->andReturn($this->mockedPayload);
        $mnsQueue->shouldReceive('getSeconds')
                 ->once()
                 ->with($this->delay)
                 ->andReturn($this->delay);
        $this->queueClient->shouldReceive('sendMessage')
                          ->once()
                          ->with(m::type(SendMessageRequest::class))
                          ->andReturn($this->response);
        $this->response->shouldReceive('getMessageId')
                       ->withNoArgs()
                       ->andReturn(123);
        $id = $mnsQueue->later($this->delay, $this->mockedJob, $this->mockedData, $this->default);
        $this->assertEquals(123, $id);
    }

    public function testLaterProperlyPushesDelayedUsingDatetimeOntoMns()
    {
        $now = Carbon::now();
        $mnsAdapter = new MnsAdapter($this->mnsClient, $this->default);
        $mnsQueue = m::mock(MnsQueue::class . '[createPayload, getSeconds]', [$mnsAdapter])
                     ->shouldAllowMockingProtectedMethods();
        $mnsQueue->shouldReceive('createPayload')
                 ->once()
                 ->with($this->mockedJob, $this->mockedData)
                 ->andReturn($this->mockedPayload);
        $mnsQueue->shouldReceive('getSeconds')
                 ->once()
                 ->with($now)
                 ->andReturn(3600);
        $this->queueClient->shouldReceive('sendMessage')
                          ->once()
                          ->with(m::type(SendMessageRequest::class))
                          ->andReturn($this->response);
        $this->response->shouldReceive('getMessageId')
                       ->withNoArgs()
                       ->andReturn(123);
        $id = $mnsQueue->later($now->addSeconds(3600), $this->mockedJob, $this->mockedData, $this->default);
        $this->assertEquals(123, $id);
    }
    
    public function testPopProperlyPopOffFromJobMns()
    {
        $mnsAdapter = new MnsAdapter($this->mnsClient, $this->default);
        $response = m::mock(ReceiveMessageResponse::class);
        $mnsQueue = m::mock(MnsQueue::class, [$mnsAdapter])->makePartial();
        $mnsQueue->setContainer(m::mock(\Illuminate\Container\Container::class));
        $this->queueClient->shouldReceive('receiveMessage')
                          ->once()
                          ->withNoArgs()
                          ->andReturn($response);
        $mnsQueue->pop($this->default);
    }

    public function testPopProperlyPopOffJobWithCustomCreator()
    {
        $mnsAdapter = new MnsAdapter($this->mnsClient, $this->default);
        $response = m::mock(ReceiveMessageResponse::class);
        $mnsQueue = m::mock(MnsQueue::class, [$mnsAdapter])->makePartial();
        $mnsQueue->createJobsUsing(function () {
            return 'job';
        });
        $mnsQueue->setContainer(m::mock(\Illuminate\Container\Container::class));
        $this->queueClient->shouldReceive('receiveMessage')
                          ->once()
                          ->withNoArgs()
                          ->andReturn($response);
        $result = $mnsQueue->pop($this->default);
        $this->assertEquals('job', $result);
    }

    public function testGetDefaultIfNullCanResolveWantedQueueNameOrReturnDefault()
    {
        $msnQueue = new MnsQueue(
            new MnsAdapter($this->mnsClient, $this->default)
        );
        $this->assertEquals('default', $msnQueue->getDefaultIfNull(null));
        $this->assertEquals('somequeue', $msnQueue->getDefaultIfNull('somequeue'));
    }
}
