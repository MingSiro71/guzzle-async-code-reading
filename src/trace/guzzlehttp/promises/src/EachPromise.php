<?php

namespace GuzzleHttp\Promise;

/**
 * Represents a promise that iterates over many promises and invokes
 * side-effect functions in the process.
 */
class EachPromise implements PromisorInterface
{
    private $pending = [];

    private $nextPendingIndex = 0;

    /** @var \Iterator|null */
    private $iterable;

    /** @var callable|int|null */
    private $concurrency;

    /** @var callable|null */
    private $onFulfilled;

    /** @var callable|null */
    private $onRejected;

    /** @var Promise|null */
    private $aggregate;

    /** @var bool|null */
    private $mutex;

    /**
     * Configuration hash can include the following key value pairs:
     *
     * - fulfilled: (callable) Invoked when a promise fulfills. The function
     *   is invoked with three arguments: the fulfillment value, the index
     *   position from the iterable list of the promise, and the aggregate
     *   promise that manages all of the promises. The aggregate promise may
     *   be resolved from within the callback to short-circuit the promise.
     * - rejected: (callable) Invoked when a promise is rejected. The
     *   function is invoked with three arguments: the rejection reason, the
     *   index position from the iterable list of the promise, and the
     *   aggregate promise that manages all of the promises. The aggregate
     *   promise may be resolved from within the callback to short-circuit
     *   the promise.
     * - concurrency: (integer) Pass this configuration option to limit the
     *   allowed number of outstanding concurrently executing promises,
     *   creating a capped pool of promises. There is no limit by default.
     *
     * @param mixed $iterable Promises or values to iterate.
     * @param array $config   Configuration options
     */
    public function __construct($iterable, array $config = [])
    {
        trace('Set generator into "iterable" and remember callbacks.');
        $this->iterable = Create::iterFor($iterable);

        if (isset($config['concurrency'])) {
            $this->concurrency = $config['concurrency'];
        }

        if (isset($config['fulfilled'])) {
            $this->onFulfilled = $config['fulfilled'];
        }

        if (isset($config['rejected'])) {
            $this->onRejected = $config['rejected'];
        }
    }

    /** @psalm-suppress InvalidNullableReturnType */
    public function promise()
    {
        if ($this->aggregate) {
            trace('Aggregate promise is already exist. Return back the aggregate.');
            return $this->aggregate;
        }

        try {
            $this->createPromise();
            /** @psalm-assert Promise $this->aggregate */
            $this->iterable->rewind();
            $this->refillPending();
        } catch (\Throwable $e) {
            /**
             * @psalm-suppress NullReference
             * @phpstan-ignore-next-line
             */
            $this->aggregate->reject($e);
        } catch (\Exception $e) {
            /**
             * @psalm-suppress NullReference
             * @phpstan-ignore-next-line
             */
            $this->aggregate->reject($e);
        }

        /**
         * @psalm-suppress NullableReturnStatement
         * @phpstan-ignore-next-line
         */
        return $this->aggregate;
    }

    private function createPromise()
    {
        $this->mutex = false;
        trace('Generate the aggregate promise.');
        $this->aggregate = new Promise(function () {
            trace('"aggregate" is invoked ("wait" is called).');
            if ($this->checkIfFinished()) {
                return;
            }
            reset($this->pending);
            // Consume a potentially fluctuating list of promises while
            // ensuring that indexes are maintained (precluding array_shift).
            while ($promise = current($this->pending)) {
                trace('A pending promise will invoked ("wait" will called).');
                next($this->pending);
                $promise->wait();
                if (Is::settled($this->aggregate)) {
                    trace('"aggregate" is settled. Resolve "aggregate".');
                    return;
                }
            }
        });

        // Clear the references when the promise is resolved.
        $clearFn = function () {
            $this->iterable = $this->concurrency = $this->pending = null;
            $this->onFulfilled = $this->onRejected = null;
            $this->nextPendingIndex = 0;
        };

        $this->aggregate->then($clearFn, $clearFn);
    }

    private function refillPending()
    {
        trace('Start.');
        if (!$this->concurrency) {
            // Add all pending promises.
            while ($this->addPending() && $this->advanceIterator());
            return;
        }

        // Add only up to N pending promises.
        $concurrency = is_callable($this->concurrency)
            ? call_user_func($this->concurrency, count($this->pending))
            : $this->concurrency;
        $concurrency = max($concurrency - count($this->pending), 0);
        // Concurrency may be set to 0 to disallow new promises.
        if (!$concurrency) {
            return;
        }
        // Add the first pending promise.
        $this->addPending();
        // Note this is special handling for concurrency=1 so that we do
        // not advance the iterator after adding the first promise. This
        // helps work around issues with generators that might not have the
        // next value to yield until promise callbacks are called.
        while (
            (--$concurrency
            && $this->advanceIterator()
            && $this->addPending())
            || trace("Advance iterator, add pending and decrement concurrency: $concurrency")
        );
    }

    private function addPending()
    {
        if (!$this->iterable || !$this->iterable->valid()) {
            trace('"iterable" is empty or its iterator indicates empty position.');
            return false;
        }

        trace('Create a promise from the element at the current position of "iterable".');
        $promise = Create::promiseFor($this->iterable->current());
        $key = $this->iterable->key();

        // Iterable keys may not be unique, so we use a counter to
        // guarantee uniqueness
        $idx = $this->nextPendingIndex++;
        trace("Bind callbacks to the promise and push it into \"pending\" by index: $idx.");

        $this->pending[$idx] = $promise->then(
            function ($value) use ($idx, $key) {
                trace("The \"pending\" indexed with $idx has been resolved. The callback may called.");
                if ($this->onFulfilled) {
                    call_user_func(
                        $this->onFulfilled,
                        $value,
                        $key,
                        $this->aggregate
                    );
                }
                $this->step($idx);
            },
            function ($reason) use ($idx, $key) {
                trace("The \"pending\" indexed with $idx has been rejected. The callback may called.");
                if ($this->onRejected) {
                    call_user_func(
                        $this->onRejected,
                        $reason,
                        $key,
                        $this->aggregate
                    );
                }
                $this->step($idx);
            }
        );

        return true;
    }

    private function advanceIterator()
    {
        // Place a lock on the iterator so that we ensure to not recurse,
        // preventing fatal generator errors.
        if ($this->mutex) {
            trace('Skip advancing iterator. "aggregate" is already rejected.');
            return false;
        }

        $this->mutex = true;

        try {
            trace('Advance iterator. This means call client\'s sendAsync with next request.');
            $this->iterable->next();
            $this->mutex = false;
            return true;
        } catch (\Throwable $e) {
            trace('Failed to advance iterator. Reject "aggregate".');
            $this->aggregate->reject($e);
            $this->mutex = false;
            return false;
        } catch (\Exception $e) {
            trace('Failed to advance iterator. Reject "aggregate".');
            $this->aggregate->reject($e);
            $this->mutex = false;
            return false;
        }
    }

    private function step($idx)
    {
        trace('Start');
        // If the promise was already resolved, then ignore this step.
        if (Is::settled($this->aggregate)) {
            return;
        }

        unset($this->pending[$idx]);

        // Only refill pending promises if we are not locked, preventing the
        // EachPromise to recursively invoke the provided iterator, which
        // cause a fatal error: "Cannot resume an already running generator"
        if ($this->advanceIterator() && !$this->checkIfFinished()) {
            // Add more pending promises if possible.
            trace('Refill pending because "iterable" is still valid position.');
            $this->refillPending();
        }
    }

    private function checkIfFinished()
    {
        if (!$this->pending && !$this->iterable->valid()) {
            // Resolve the promise if there's nothing left to do.
            $this->aggregate->resolve(null);
            return true;
        }

        return false;
    }
}
