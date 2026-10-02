<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Reseller\AdminResellerTicketController;
use CodeVault\Reseller\ClientResellerTicketController;
use PHPUnit\Framework\TestCase;

/**
 * The seams around the reseller ticket desk: route strings, path ordering, and the
 * link that makes the desk reachable at all.
 *
 * These are the failures that leave every other test green while the feature does not
 * work in a browser — a route naming a method that was renamed, a literal path
 * swallowed by a parameterised one, or a page nobody can navigate to. None of them
 * needs a database, so this class is deliberately not a DatabaseTestCase: it costs
 * nothing to run and cannot collide with the shared test schema.
 */
final class ResellerTicketDeskSeamTest extends TestCase
{
    private function routes(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/routes/reseller.php');
    }

    /**
     * Every route must name a method that exists.
     *
     * The route file and the controller are separate files joined by two strings no
     * compiler checks: rename a method and the route still parses, the site still
     * boots, and the page 500s only when somebody clicks it.
     *
     * @return array<string, array{0: class-string, 1: array<int, string>}>
     */
    public static function routeTables(): array
    {
        return [
            'reseller' => [ClientResellerTicketController::class, ['index', 'show', 'reply', 'escalate', 'withdraw']],
            'admin' => [AdminResellerTicketController::class, ['index']],
        ];
    }

    /**
     * @param class-string       $controller
     * @param array<int, string> $methods
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeTables')]
    public function test_every_ticket_route_names_a_method_that_exists(string $controller, array $methods): void
    {
        $routes = $this->routes();
        $short = substr($controller, strrpos($controller, '\\') + 1);

        foreach ($methods as $method) {
            $this->assertStringContainsString(
                $short . '::class, ' . "'" . $method . "'",
                $routes,
                "No route is declared for {$short}::{$method}()."
            );

            $this->assertTrue(
                method_exists($controller, $method),
                "The route for {$short}::{$method}() points at a method that does not exist."
            );
        }
    }

    public function test_the_literal_escalations_path_precedes_the_parameterised_store_routes(): void
    {
        // '/admin/resellers/escalations' and '/admin/resellers/{clientId}/store' are
        // both candidates for a request to the first one, and the router takes the
        // first match — so order is load-bearing, not cosmetic. If this ever flips,
        // the queue becomes a page that reports "Client #escalations". Same trap the
        // accounts and domains literal paths are guarded against.
        $routes = $this->routes();

        $literal = strpos($routes, "'/admin/resellers/escalations'");
        $parameterised = strpos($routes, "'/admin/resellers/{clientId}/store'");

        $this->assertNotFalse($literal, 'the escalations path is not registered at all');
        $this->assertNotFalse($parameterised, 'the parameterised store path is not registered at all');
        $this->assertLessThan(
            $parameterised,
            $literal,
            'the literal escalations path must be registered before the parameterised store routes, or it can be read as a client id'
        );
    }

    public function test_the_reseller_ticket_routes_are_registered_as_literal_paths_before_any_ticket_parameter(): void
    {
        // '/client/reseller/tickets' must not be reachable as a ticket id, and the
        // collection route has to be declared before the item route for the same
        // first-match reason as above.
        $routes = $this->routes();

        $list = strpos($routes, "'/client/reseller/tickets'");
        $item = strpos($routes, "'/client/reseller/tickets/{ticketId}'");

        $this->assertNotFalse($list, 'the ticket list path is not registered');
        $this->assertNotFalse($item, 'the ticket detail path is not registered');
        $this->assertLessThan($item, $list, 'the ticket list must be declared before the ticket detail route');
    }

    /**
     * A desk nobody can navigate to is not a feature. The reseller area's hub is the
     * only route into it, so it must carry the link.
     */
    public function test_the_ticket_desk_is_linked_from_the_reseller_area(): void
    {
        $hub = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/reseller/client-index.php');

        $this->assertStringContainsString(
            '/client/reseller/tickets',
            $hub,
            'The reseller area does not link to the support desk, so nothing can reach it.'
        );
    }

    public function test_the_escalation_queue_is_linked_from_the_admin_navigation(): void
    {
        $nav = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/partials/admin-nav.php');

        $this->assertStringContainsString(
            '/admin/resellers/escalations',
            $nav,
            'The escalation queue is not in the admin navigation, so nobody would find it.'
        );
    }
}
