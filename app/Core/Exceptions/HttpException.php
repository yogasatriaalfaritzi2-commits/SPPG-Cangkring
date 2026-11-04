<?php
declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(private readonly int $statusCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Permintaan tidak dapat diproses.');
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
