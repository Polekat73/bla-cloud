<?php
declare(strict_types=1);

namespace BlaCloud;

/** An error whose message is safe and friendly enough to show to the user. */
final class StorageException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 400)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
