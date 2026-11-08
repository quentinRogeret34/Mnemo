<?php

namespace App\Enum;

enum Confidence: string
{
    case Extracted = 'EXTRACTED';
    case Inferred = 'INFERRED';
    case Verified = 'VERIFIED';
}
