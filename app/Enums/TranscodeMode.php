<?php

namespace App\Enums;

enum TranscodeMode: string
{
    case Direct = 'direct';
    case Server = 'server';
    case Local = 'local';
}
