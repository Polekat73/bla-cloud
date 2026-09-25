<?php $instance = \BlaCloud\Config::get('instance_name', 'BLA-Cloud'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#07070F">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= e(($title ?? '') !== '' ? $title . ' · ' . $instance : $instance) ?></title>
  <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
