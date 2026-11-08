<?php

namespace App\Enum;

enum NodeType: string
{
    case CodeFile = 'code_file';
    case Symbol = 'symbol';
    case Commit = 'commit';
    case Person = 'person';
    case Ticket = 'ticket';
    case Document = 'document';
    case BusinessRule = 'business_rule';
    case Memory = 'memory';
}
