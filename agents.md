# AGENTS.md

Reguli pentru agentul AI (DeepSeek) pe acest repo: PHP/Symfony + PostgreSQL (Doctrine) + RabbitMQ (Messenger).

## Structură obligatorie

```
src/
  Domain/        # logică de business pură. FĂRĂ Symfony, FĂRĂ Doctrine, FĂRĂ AMQP.
  Application/   # command/query handlers, orchestrare use-case-uri
  Infrastructure/
    Persistence/ # Doctrine repos + mappings
    Messaging/   # Messenger transports/handlers
  UI/            # controllers, CLI
```
Dependențele curg spre interior. Domain nu importă niciodată din Infrastructure/UI.

## Reguli de design (aplică, nu explica)

- Entități = identitate + comportament. Value Objects = imutabile (Money, Email...).
- Logică de business în Domain, nu în controller/entity-anemică.
- Repository = interfață în Domain, implementare Doctrine în Infrastructure.
- Command (schimbare) vs Query (citire) — bus-uri separate în messenger.yaml, nu unul singur pentru tot.
- Injectează interfețe, nu clase concrete Doctrine/AMQP, în servicii și handlere.
- Interfețe mici, specifice per use-case — nu un repository „god interface”.

## RabbitMQ / Messenger

- Orice consumer trebuie idempotent (poate primi același mesaj de 2x). Verifică/deduplichează prin message_id.
- Retry cu backoff + failed transport configurat. Niciun mesaj nu cade silențios.
- Outbox pattern când o acțiune trebuie atomică cu un eveniment publicat: scrii în tabel `outbox` în aceeași tranzacție DB, worker separat publică.
- Payload-ul mesajului = contract; schimbări backward-compatible (adaugi câmpuri, nu redenumești/ștergi).
- Loghează message_id + correlation_id la consum.

## PostgreSQL / Doctrine

- Schema se schimbă DOAR prin migrații Doctrine, niciodată manual.
- Fără `findAll()`/hidratare completă pe liste mari — query builder + paginare.
- Indecși pe coloane din WHERE/JOIN frecvente, inclusiv pe coloanele de dedup/outbox.
- Tranzacții explicite pentru orice operație care trebuie atomică.

## Cod

- `declare(strict_types=1);` peste tot, tipuri stricte, fără `mixed` evitabil.
- PSR-12. Numire: comenzi `VerbNoun`, evenimente la trecut (`OrderPlaced`), handlere `{Mesaj}Handler`.
- La cod nou → test corespunzător (unit pe Domain, integrare pe Infrastructure/Messaging).
- Orice handler de mesaj are test care verifică idempotența (mesaj de 2x → un singur efect).

## Comenzi de verificare (adaptează la composer.json real)

```
composer install
bin/console doctrine:migrations:migrate --dry-run
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Interzis pentru agent

- Modificare schemă DB fără migrație.
- Dependențe Symfony/Doctrine/AMQP în Domain/.
- Publicare mesaj + scriere DB în același pas fără tranzacție/outbox.
- Refactoring masiv într-un singur commit — schimbări mici, reversibile.
- Presupuneri pe bounded context/ambiguități — întreabă în loc să inventezi.
