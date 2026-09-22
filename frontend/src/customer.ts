const STORAGE_KEY = 'orderProcess.customerId'

/**
 * There is no auth in this project, but the API still wants a customer UUID.
 * One is minted per browser and reused, so a visitor's orders stay grouped.
 * localStorage can throw (private mode, blocked site data), hence the guards.
 */
export function currentCustomerId(): string {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored !== null && stored !== '') {
      return stored
    }
  } catch {
    // Storage unavailable: fall through and mint a throwaway id.
  }

  const generated = crypto.randomUUID()

  try {
    localStorage.setItem(STORAGE_KEY, generated)
  } catch {
    // Not persisted; the session still works, the id just won't survive a reload.
  }

  return generated
}

export function resetCustomerId(): string {
  try {
    localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Nothing to clear.
  }

  return currentCustomerId()
}
