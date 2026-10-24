<?php

/*
|--------------------------------------------------------------------------
| Escalation ladder (.ai/05-CARE-COORDINATION.md §6)
|--------------------------------------------------------------------------
|
| `defaults` is a *suggestion only*. Nothing reads it automatically: a
| workspace starts with no policies and therefore escalates nothing until
| someone applies the defaults through `POST .../escalation-policies/defaults`
| or writes their own ladder. This keeps escalation deny-by-default (F-01).
|
| `after_minutes` is measured from the task's `due_at`. `notify_roles` holds
| workspace membership roles, never user ids.
|
*/

return [

    'defaults' => [
        [
            'level' => 1,
            'after_minutes' => 30,
            'notify_assignee' => true,
            'notify_roles' => [],
            'action' => 'notify',
            'is_active' => true,
        ],
        [
            'level' => 2,
            'after_minutes' => 120,
            'notify_assignee' => false,
            'notify_roles' => ['workspace_admin'],
            'action' => 'notify',
            'is_active' => true,
        ],
        [
            'level' => 3,
            'after_minutes' => 360,
            'notify_assignee' => false,
            'notify_roles' => ['workspace_admin'],
            'action' => 'reassign_to_admin',
            'is_active' => true,
        ],
    ],

];
