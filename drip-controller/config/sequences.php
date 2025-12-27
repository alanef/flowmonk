<?php
/**
 * Drip Sequence Configuration
 *
 * Defines email sequences for each plugin and status type.
 * Template IDs are placeholders - configure actual IDs in Listmonk.
 *
 * Structure:
 *   plugin_id => [
 *       'type' => [
 *           ['stage' => 'name', 'template_id' => N, 'delay_days' => N, 'subject' => 'text'],
 *           ...
 *       ],
 *   ]
 */

return [
    // Display Eventbrite (Plugin ID: 1330)
    '1330' => [
        'free' => [
            ['stage' => 'free_1', 'template_id' => 1, 'delay_days' => 0, 'subject' => 'Welcome to Display Eventbrite'],
            ['stage' => 'free_2', 'template_id' => 2, 'delay_days' => 3, 'subject' => 'Getting the most from Display Eventbrite'],
            ['stage' => 'free_3', 'template_id' => 3, 'delay_days' => 7, 'subject' => 'Pro tips for Display Eventbrite'],
            ['stage' => 'free_4', 'template_id' => 4, 'delay_days' => 30, 'subject' => 'Unlock premium features'],
        ],
        'trial' => [
            ['stage' => 'trial_1', 'template_id' => 5, 'delay_days' => 0, 'subject' => 'Your trial has started'],
            ['stage' => 'trial_2', 'template_id' => 6, 'delay_days' => 3, 'subject' => 'Making the most of your trial'],
            ['stage' => 'trial_3', 'template_id' => 7, 'delay_days' => 7, 'subject' => 'Trial ending soon'],
        ],
        'premium' => [
            ['stage' => 'premium_1', 'template_id' => 8, 'delay_days' => 0, 'subject' => 'Thank you for your purchase'],
        ],
    ],

    // Fullworks Anti-Spam (Plugin ID: 5065)
    '5065' => [
        'free' => [
            ['stage' => 'free_1', 'template_id' => 10, 'delay_days' => 0, 'subject' => 'Welcome to Fullworks Anti-Spam'],
            ['stage' => 'free_2', 'template_id' => 11, 'delay_days' => 3, 'subject' => 'Protecting your site from spam'],
            ['stage' => 'free_3', 'template_id' => 12, 'delay_days' => 7, 'subject' => 'Advanced spam protection tips'],
            ['stage' => 'free_4', 'template_id' => 13, 'delay_days' => 30, 'subject' => 'Unlock premium protection'],
        ],
        'trial' => [
            ['stage' => 'trial_1', 'template_id' => 14, 'delay_days' => 0, 'subject' => 'Your Anti-Spam trial has started'],
            ['stage' => 'trial_2', 'template_id' => 15, 'delay_days' => 3, 'subject' => 'Getting the most from your trial'],
            ['stage' => 'trial_3', 'template_id' => 16, 'delay_days' => 7, 'subject' => 'Trial ending soon'],
        ],
        'premium' => [
            ['stage' => 'premium_1', 'template_id' => 17, 'delay_days' => 0, 'subject' => 'Thank you for upgrading'],
        ],
    ],

    // Quick PayPal Payments (Plugin ID: 5623)
    '5623' => [
        'free' => [
            ['stage' => 'free_1', 'template_id' => 20, 'delay_days' => 0, 'subject' => 'Welcome to Quick PayPal Payments'],
            ['stage' => 'free_2', 'template_id' => 21, 'delay_days' => 3, 'subject' => 'Setting up your first payment button'],
            ['stage' => 'free_3', 'template_id' => 22, 'delay_days' => 7, 'subject' => 'Tips for increasing conversions'],
            ['stage' => 'free_4', 'template_id' => 23, 'delay_days' => 30, 'subject' => 'Unlock premium features'],
        ],
        'trial' => [
            ['stage' => 'trial_1', 'template_id' => 24, 'delay_days' => 0, 'subject' => 'Your QPP trial has started'],
            ['stage' => 'trial_2', 'template_id' => 25, 'delay_days' => 3, 'subject' => 'Making the most of your trial'],
            ['stage' => 'trial_3', 'template_id' => 26, 'delay_days' => 7, 'subject' => 'Trial ending soon'],
        ],
        'premium' => [
            ['stage' => 'premium_1', 'template_id' => 27, 'delay_days' => 0, 'subject' => 'Thank you for your purchase'],
        ],
    ],
];
