<?php

namespace AssistantEngine\OpenFunctions\Bitbucket\Models;

class Parameters
{
    public string $token;
    public string $owner;
    public string $repository;
    public array $protected = [];
}
