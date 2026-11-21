<?php

namespace Sevaske\Zatca\Enums;

/**
 * Standard (B2B) documents are cleared before they reach the buyer;
 * simplified (B2C) documents are reported within 24 hours.
 */
enum Profile: string
{
    case Standard = 'standard';
    case Simplified = 'simplified';
}
