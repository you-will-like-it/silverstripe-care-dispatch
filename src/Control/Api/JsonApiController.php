<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api;

use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use Throwable;
use YouWillLikeIT\CareDispatch\Domain\Violation;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\CareDispatchException;

/**
 * Base for the JSON APIs: session auth, permission check, CSRF on mutating requests,
 * JSON body parsing and uniform error responses.
 *
 * Error format: {"error": {"code": "...", "message": "...", "violations": [...]}}
 */
abstract class JsonApiController extends Controller
{
    /** Permission code(s) required for every action of the controller; with several codes any one suffices. */
    private static string|array $required_permission = '';

    protected function init(): void
    {
        parent::init();

        $member = Security::getCurrentUser();
        if ($member === null) {
            throw new HTTPResponse_Exception($this->error(401, 'UNAUTHENTICATED', 'Bitte anmelden.'));
        }
        $codes = array_filter((array) static::config()->get('required_permission'));
        if ($codes !== [] && !Permission::checkMember($member, array_values($codes))) {
            throw new HTTPResponse_Exception($this->error(403, 'ACCESS_DENIED', 'Keine Berechtigung für diese Aktion.'));
        }
        $request = $this->getRequest();
        if (!$request->isGET() && !$request->isHEAD() && !SecurityToken::inst()->checkRequest($request)) {
            throw new HTTPResponse_Exception($this->error(400, 'CSRF_INVALID', 'Sicherheitstoken ungültig. Bitte Seite neu laden.'));
        }
    }

    /** Per-action check; any of the codes suffices. Throws a JSON 403. */
    protected function requirePermission(string ...$codes): void
    {
        if (!Permission::checkMember($this->currentMember(), $codes)) {
            throw new HTTPResponse_Exception($this->error(403, 'ACCESS_DENIED', 'Keine Berechtigung für diese Aktion.'));
        }
    }

    protected function currentMember(): Member
    {
        return Security::getCurrentUser() ?? throw new HTTPResponse_Exception($this->error(401, 'UNAUTHENTICATED', 'Bitte anmelden.'));
    }

    /**
     * Executes an action and maps exceptions to JSON errors.
     *
     * @param callable(): (array<string, mixed>|HTTPResponse) $action
     */
    protected function handle(callable $action, int $successStatus = 200): HTTPResponse
    {
        try {
            $result = $action();

            return $result instanceof HTTPResponse ? $result : $this->json($result, $successStatus);
        } catch (CareDispatchException $e) {
            return $this->error($e->httpStatus(), $e->errorCode(), $e->getMessage(), $e->violations());
        } catch (ValidationException $e) {
            $messages = [];
            $fields = [];
            foreach ($e->getResult()->getMessages() as $message) {
                $text = (string) ($message['message'] ?? '');
                $messages[] = $text;
                if (!empty($message['fieldName'])) {
                    $fields[(string) $message['fieldName']] = $text;
                }
            }

            return $this->error(422, 'VALIDATION_FAILED', implode(' ', array_filter($messages)) ?: $e->getMessage(), [], $fields);
        } catch (InvalidArgumentException $e) {
            return $this->error(400, 'BAD_REQUEST', $e->getMessage());
        } catch (HTTPResponse_Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error(
                'CareDispatch API error: ' . $e->getMessage(),
                ['exception' => $e, 'url' => $this->getRequest()->getURL(true)]
            );

            return $this->error(500, 'SERVER_ERROR', 'Interner Fehler. Der Vorgang wurde nicht ausgeführt.');
        }
    }

    /** @return array<string, mixed> */
    protected function body(): array
    {
        $raw = (string) $this->getRequest()->getBody();
        if (trim($raw) === '') {
            return $this->getRequest()->postVars();
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw BadRequestException::because('Ungültiges JSON im Request-Body.');
        }
        if (!is_array($data)) {
            throw BadRequestException::because('JSON-Objekt erwartet.');
        }

        return $data;
    }

    /** @param array<string, mixed> $body */
    protected function intParam(array $body, string $key, bool $required = true): ?int
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw BadRequestException::because(sprintf('Parameter "%s" fehlt.', $key));
            }

            return null;
        }
        if (!is_numeric($value) || (int) $value <= 0) {
            throw BadRequestException::because(sprintf('Parameter "%s" muss eine positive Zahl sein.', $key));
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $body */
    protected function stringParam(array $body, string $key, int $maxLength = 2000): string
    {
        $value = $body[$key] ?? '';
        if (!is_scalar($value)) {
            throw BadRequestException::because(sprintf('Parameter "%s" muss ein Text sein.', $key));
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $maxLength) {
            throw BadRequestException::because(sprintf('Parameter "%s" ist zu lang (max. %d Zeichen).', $key, $maxLength));
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    protected function boolParam(array $body, string $key): bool
    {
        return filter_var($body[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function routeID(HTTPRequest $request, string $param = 'ID'): int
    {
        $id = $request->param($param);
        if (!is_string($id) || !ctype_digit($id) || (int) $id <= 0) {
            throw BadRequestException::because('Ungültige ID in der URL.');
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): HTTPResponse
    {
        $response = HTTPResponse::create();
        $response->setStatusCode($status);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->addHeader('Cache-Control', 'no-store, private');
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->setBody((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $response;
    }

    /**
     * @param Violation[]           $violations
     * @param array<string, string> $fields field name => message (form validation)
     */
    protected function error(int $status, string $code, string $message, array $violations = [], array $fields = []): HTTPResponse
    {
        return $this->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'violations' => $violations,
                'fields' => (object) $fields,
            ],
        ], $status);
    }
}
