<?php

declare(strict_types=1);

// Exit clearance (ExitClearanceService, ExitClearanceTemplate).
return [
    'items' => [
        'id_card' => 'ID and access card returned',
        'property' => 'Company property returned',
        'handover' => 'Work handed over',
        'obligations' => 'Loans and advances settled',
        'final_pay' => 'Final pay reviewed',
    ],
    'note_required_with_balance' => 'A balance is still owed. Add a note to say how it is being settled.',
    'not_leaving' => 'An exit clearance is for someone who has resigned, been terminated or retired.',
    'one_obligations_line' => 'Only one line can show the outstanding balance.',
];
