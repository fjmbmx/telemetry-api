<?php

namespace App\Application\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class JsonMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Parse JSON body automatically
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $body = (string)$request->getBody();
            $data = json_decode($body, true);
            if (is_array($data)) {
                $request = $request->withParsedBody($data);
            }
        }

        return $handler->handle($request)
            ->withHeader('Content-Type', 'application/json');
    }
}
