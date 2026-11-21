<?php

namespace Sevaske\Zatca\Enums;

enum DocumentType: string
{
    case Invoice = 'invoice';
    case Credit = 'credit';
    case Debit = 'debit';
}
