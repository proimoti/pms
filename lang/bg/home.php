<?php

// „Начало“ (PROJECT.md, „Поправки по екраните“ и „Общ вид“, 24.11.2026).
return [
    'title' => 'Начало',
    'buttons' => [
        'import' => 'Внос на сметки',
        'new_lease' => 'Нов договор за наем',
        'generate' => 'Генерирай месеца',
        'worksheet' => 'Работна таблица',
    ],
    'states' => [
        'none' => 'Не е генериран',
        'generated' => 'Генериран, не е издаден',
        'issued' => 'Издаден',
    ],
    'occupancy' => 'Заетост',
    'occupancy_of' => ':occupied от :total имота',
    'rent' => 'Начислен наем',
    'to_owners' => 'към собствениците :amount €',
    'commission' => 'Комисиона на фирмата',
    'decide' => [
        'title' => 'Иска решение',
        'import' => 'Сметки „За решение“ от вноса',
        'unallocated' => 'Неразпределени сметки',
        'contracts' => 'Договор „за решение“ в :month',
        'no_iban' => 'Плащане по банка без IBAN',
        'nothing' => 'Нищо не чака решение.',
    ],
    'follow' => [
        'title' => 'За проследяване',
        'expiring' => 'Изтичат до 30 дни: № :number :name (:date)',
        'days' => ':days дни',
        'expired' => 'Изтекъл срок без прекратяване: № :number :name',
        'expired_owner' => 'Изтекъл срок на договор за управление: № :number :name',
        'since' => 'от :date',
        'vacant' => 'Свободни имоти',
        'nothing' => 'Нищо за проследяване.',
    ],
];
