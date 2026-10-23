<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Schema;

/** How a field's values are checked (the mapping's FieldValidator). */
enum FieldKind: string
{
    case Text = 'text';
    case Html = 'html';
    case Code = 'code';
    case Gtin = 'gtin';
    case Money = 'money';
    case Currency = 'currency';
    case Integer = 'integer';
    case Number = 'number';
    case Url = 'url';
    case Urls = 'urls';
    case Category = 'category';
}
