<?php

declare(strict_types=1);

namespace XArticlePdf;

/**
 * Rzucany, gdy użytkownik zatrzymał zadanie. Świadomie NIE dziedziczy po FetchException,
 * żeby pętle ponawiania w tłumaczu nie potraktowały stopu jako błędu do powtórzenia.
 */
final class JobCancelledException extends \RuntimeException
{
}
