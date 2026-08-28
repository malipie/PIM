<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

/*
 * @deprecated Use {@see \App\Catalog\Contracts\AttributeType}. Kept as a
 *             runtime alias so integrations compiled against the former FQCN
 *             keep resolving while the architecture baseline is burned down.
 */
class_alias(\App\Catalog\Contracts\AttributeType::class, __NAMESPACE__.'\\AttributeType');
