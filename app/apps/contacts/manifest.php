<?php
declare(strict_types=1);

use BlaCloud\Apps\Contacts\ContactsController;

return [
    'id'          => 'contacts',
    'name'        => 'Contacts',
    'description' => 'A searchable address book over your CardDAV contacts, with photos and notes.',
    'version'     => '1.0.0',
    'icon'        => 'contact',
    'nav'         => ['route' => 'contacts', 'label' => 'Contacts', 'order' => 30],
    'default_enabled' => true,
    'routes'      => [
        'contacts'        => [ContactsController::class, 'index'],
        'contacts.save'   => [ContactsController::class, 'save'],
        'contacts.delete' => [ContactsController::class, 'delete'],
    ],
];
