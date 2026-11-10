<?php

namespace App\Enum;

enum ProposalStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Rejected = 'rejected';
}
