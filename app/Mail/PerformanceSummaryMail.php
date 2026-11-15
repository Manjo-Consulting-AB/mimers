<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Veckosammanställningen av prestandaloggen till driftadressen, issue 390,
 * [[ADR-0079 Övervakning]] § 3. Byggs av App\Console\SummarizesPerformance.
 * Engelska, texterna i lang/en/prestanda.php.
 */
class PerformanceSummaryMail extends Mailable
{
    /**
     * @param  list<array{route: string, count: int, median: float, p95: float}>  $routes
     * @param  list<array{sql: string, count: int, max: float}>  $queries
     */
    public function __construct(
        public readonly array $routes,
        public readonly array $queries,
        public readonly int $requestsThisWeek,
        public readonly int $requestsPreviousWeek,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans('prestanda.subject', [], 'en'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.performance-summary',
            with: [
                'routes' => $this->routes,
                'queries' => $this->queries,
                'requestsThisWeek' => $this->requestsThisWeek,
                'requestsPreviousWeek' => $this->requestsPreviousWeek,
            ],
        );
    }
}
