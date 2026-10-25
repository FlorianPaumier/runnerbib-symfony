<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Application\Operations\TransactionManager;
use App\Domain\Identity\Signup;

final readonly class RegisterAccount
{
    public function __construct(private IdentityStore $store, private IdentityEffects $effects, private TransactionManager $transactions, private AuditWriter $audit)
    {
    }

    public function register(string $email, string $password, bool $termsAccepted): void
    {
        try {
            $signup = new Signup($email, $password, $termsAccepted);
        } catch (\InvalidArgumentException $error) {
            throw new InvalidSignup($error->getMessage(), previous: $error);
        }
        $this->transactions->run(function () use ($signup): void {
            $id = $this->effects->newId();
            if ($this->store->register($id, $signup->email, $this->effects->hashPassword($signup->password), 'launch-v1', $this->effects->now())) {
                $this->issue($id, $signup->email, 'verification');
                $this->audit->append(new AuditEntry($this->effects->newId(), $id, null, '{"scope":"signup"}', 'account.registered', 'account', $id, null, $this->effects->now(), 'completed'));
            }
        });
    }

    public function verify(string $token): bool
    {
        return $this->store->consumeCredential(hash('sha256', $token), 'verification', $this->effects->now(), null);
    }

    public function requestVerification(string $email): void
    {
        $this->transactions->run(function () use ($email): void {
            $account = $this->store->findActive($email);
            if (null !== $account && null === $account['verified_at']) {
                $this->issue($account['id'], strtolower(trim($email)), 'verification');
            }
        });
    }

    public function requestReset(string $email): void
    {
        $this->transactions->run(function () use ($email): void {
            $account = $this->store->findActive($email);
            if (null !== $account) {
                $this->issue($account['id'], strtolower(trim($email)), 'password_reset');
            }
        });
    }

    public function reset(string $token, string $password): bool
    {
        if (strlen($password) < 12 || strlen($password) > 4096) {
            return false;
        }

        return $this->store->consumeCredential(hash('sha256', $token), 'password_reset', $this->effects->now(), $this->effects->hashPassword($password));
    }

    private function issue(string $id, string $email, string $purpose): void
    {
        $now = $this->effects->now();
        $token = $this->effects->token();
        $expiry = $now->modify('+1 hour');
        if (!$this->store->issueCredential($this->effects->newId(), $id, $purpose, hash('sha256', $token), $now, $expiry)) {
            return;
        }
        $this->effects->deliver($email, $purpose, $token, $expiry);
    }
}
