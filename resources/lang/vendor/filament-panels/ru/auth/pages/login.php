<?php

return [

    'title' => 'Вход',

    'heading' => 'Вход в систему',

    'actions' => [

        'register' => [
            'before' => 'или',
            'label' => 'создайте учетную запись',
        ],

        'request_password_reset' => [
            'label' => 'Забыли пароль?',
        ],

    ],

    'form' => [

        'email' => [
            'label' => 'Электронная почта',
        ],

        'password' => [
            'label' => 'Пароль',
        ],

        'remember' => [
            'label' => 'Запомнить меня',
        ],

        'actions' => [

            'authenticate' => [
                'label' => 'Войти',
            ],

        ],

    ],

    'multi_factor' => [

        'heading' => 'Подтвердите вход',

        'subheading' => 'Для продолжения необходимо подтвердить вашу личность.',

        'form' => [

            'provider' => [
                'label' => 'Выберите способ подтверждения',
            ],

            'actions' => [

                'authenticate' => [
                    'label' => 'Подтвердить вход',
                ],

            ],

        ],

    ],

    'messages' => [

        'failed' => 'Указанные учетные данные не совпадают с нашими записями.',

    ],

    'notifications' => [

        'throttled' => [
            'title' => 'Слишком много попыток входа',
            'body' => 'Повторите попытку через :seconds сек.',
        ],

    ],

];
