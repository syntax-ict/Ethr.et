<?php

declare(strict_types=1);

/*
 * The Gregorian day of the month on which each Ethiopian month begins, as a
 * published reference table gives it. DATA ONLY.
 *
 * Source: Wikipedia, Ethiopian calendar, section Months
 * (https://en.wikipedia.org/wiki/Ethiopian_calendar, read 2026-11-28). The page
 * says the table is valid for March 1900 to February 2100. Its two columns are
 * "usual" and "year after an Ethiopian leap day": the second applies to the
 * months of an Ethiopian year that follows a six-day Pagume. Each row is
 * Ethiopian month => [Gregorian month, day usual, day after a leap day].
 * Meskerem to Tahsas fall in September to December, Tir to Pagume in the
 * January to September that follows.
 */

return [
    1 => [9, 11, 12],
    2 => [10, 11, 12],
    3 => [11, 10, 11],
    4 => [12, 10, 11],
    5 => [1, 9, 10],
    6 => [2, 8, 9],
    7 => [3, 10, 10],
    8 => [4, 9, 9],
    9 => [5, 9, 9],
    10 => [6, 8, 8],
    11 => [7, 8, 8],
    12 => [8, 7, 7],
    13 => [9, 6, 6],
];
