<?php

declare(strict_types=1);

namespace App\Contracts;

interface DnsResolverInterface
{
    /**
     * @return list<string>
     */
    public function lookupTxt(string $name): array;

    /**
     * @return list<string>
     */
    public function lookupCname(string $name): array;
}
