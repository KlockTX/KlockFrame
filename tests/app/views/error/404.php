<?php
/**
 * 404：整页与片段共用
 *
 * @var int    $code
 * @var string $message
 */
kf_layout('layout');

echo kf_div(
    kf_h1((string)$code),
    kf_p($message ?? '')
)->id('err')->class('error');
