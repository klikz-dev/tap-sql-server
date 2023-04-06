<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

class SQLServerTapTest extends TestCase
{
    public function testHasDesiredMethods()
    {
        $this->assertTrue(method_exists('SQLServerTap', 'test'));
        $this->assertTrue(method_exists('SQLServerTap', 'discover'));
        $this->assertTrue(method_exists('SQLServerTap', 'tap'));
        $this->assertTrue(method_exists('SQLServerTap', 'getTables'));
    }
}
