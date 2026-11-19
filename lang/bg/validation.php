<?php

// Само съобщенията, които се ползват засега. Добавят се при нужда.
return [
    'alpha' => 'Полето „:attribute“ може да съдържа само букви.',
    'confirmed' => 'Полето „:attribute“ не съвпада с потвърждението.',
    'email' => 'Полето „:attribute“ трябва да е валиден имейл.',
    'enum' => 'Избраната стойност за „:attribute“ не е валидна.',
    'exists' => 'Избраната стойност за „:attribute“ не е валидна.',
    'integer' => 'Полето „:attribute“ трябва да е цяло число.',
    'max' => [
        'numeric' => 'Полето „:attribute“ може да е най-много :max.',
        'string' => 'Полето „:attribute“ може да е най-много :max знака.',
    ],
    'min' => [
        'numeric' => 'Полето „:attribute“ трябва да е поне :min.',
        'string' => 'Полето „:attribute“ трябва да е поне :min знака.',
    ],
    'numeric' => 'Полето „:attribute“ трябва да е число.',
    'prohibited_if' => 'Полето „:attribute“ не се попълва в този случай.',
    'regex' => 'Полето „:attribute“ е в грешен формат.',
    'required' => 'Полето „:attribute“ е задължително.',
    'size' => [
        'string' => 'Полето „:attribute“ трябва да е точно :size знака.',
    ],
    'string' => 'Полето „:attribute“ трябва да е текст.',
    'unique' => 'Вече има потребител с такъв „:attribute“.',

    'attributes' => [
        'email' => 'Имейл',
        'password' => 'Парола',
        'code' => 'Код',
        'recovery_code' => 'Резервен код',
        'number' => 'Номер',
        'property_type_id' => 'Вид',
        'broker_id' => 'Отговорно лице',
        'postal_code' => 'Пощенски код',
        'city' => 'Град',
        'district' => 'Квартал',
        'street' => 'Улица',
        'street_number' => 'Номер на сградата',
        'entrance' => 'Вход',
        'floor' => 'Етаж',
        'apartment' => 'Апартамент',
        'area' => 'Площ',
        'note' => 'Бележка',
        'kind' => 'Вид лице',
        'name' => 'Име',
        'eik' => 'ЕИК',
        'manager_name' => 'МОЛ',
        'country' => 'Държава',
        'phone' => 'Телефон',
    ],
];
