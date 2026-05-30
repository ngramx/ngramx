<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command\N8n;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

trait CommandTestTrait
{
    /**
     * @param array<int, mixed> $args
     */
    protected function invokeMethod(object $object, string $methodName, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invoke($object, ...$args);
    }

    /**
     * @param string $content
     */
    protected function createMockResponse(string $content, bool $useGetContents = true): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $body = $this->createMock(StreamInterface::class);

        if ($useGetContents) {
            $body->expects($this->once())
                ->method('getContents')
                ->willReturn($content);
            // Also allow __toString in case it's used
            $body->expects($this->any())
                ->method('__toString')
                ->willReturn($content);
        } else {
            // Use 'any()' for __toString when useGetContents is false
            // because some responses (like POST) may not be read
            $body->expects($this->any())
                ->method('__toString')
                ->willReturn($content);
        }

        // Use 'any()' to allow getBody() to be called zero or more times
        // This is necessary when mocks are returned from callbacks, as PHPUnit
        // may not track expectations properly in that case
        $response->expects($this->any())
            ->method('getBody')
            ->willReturn($body);

        return $response;
    }

    /**
     * Helper to safely encode JSON and create mock response
     * @param array<string, mixed> $data
     */
    protected function createMockResponseFromJson(array $data, bool $useGetContents = true): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        return $this->createMockResponse($json, $useGetContents);
    }

    protected function recursiveRemoveDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
