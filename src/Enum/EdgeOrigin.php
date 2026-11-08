<?php

namespace App\Enum;

enum EdgeOrigin: string
{
    case Ast = 'ast';
    case Git = 'git';
    case Api = 'api';
    case Llm = 'llm';
    case Human = 'human';
}
