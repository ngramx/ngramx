## Shared Step: Tests

Write test implementations for the approved specifications.

### Purpose

Implement the test code that verifies the scenarios defined in the feature specs. This follows test-driven development principles:

* Tests are written before implementation code
* Tests define the expected behavior
* Implementation is guided by making tests pass

### Prerequisites

Before this step:
* Specs must be approved (`.ngramx/specs/*.feature`)
* Approach must be selected
* Detailed plan must be approved

### What To Do

1. **Review the Feature Specs**
   * Re-read all `.feature` files for this ticket
   * Understand each scenario's intent
   * Note any shared steps that can be reused

2. **Implement Step Definitions**
   * Create Context classes for Behat/Cucumber
   * Implement Given/When/Then step definitions
   * Match step definitions to scenario language

3. **Write Supporting Test Code**
   * Test fixtures and factories
   * Mock objects where needed
   * Helper methods for common operations

4. **Organize Test Files**
   * Follow project conventions for test location
   * Group related tests logically
   * Use descriptive naming

### File Locations

Tests are placed in the main project (not `.ngramx/`):

```
project-root/
├── .ngramx/
│   └── specs/
│       └── feature-name.feature    # Specs (already created)
└── tests/
    └── features/
        ├── FeatureContext.php      # Step definitions
        ├── bootstrap/              # Test setup
        └── support/                # Helpers, fixtures
```

### Step Definition Guidelines

**Matching Spec Language:**

```gherkin
# In .ngramx/specs/invoice-creation.feature
Scenario: Create invoice with valid data
  Given I am logged in as an admin
  When I create an invoice with amount "100.00"
  Then the invoice should be saved successfully
```

```php
// In tests/features/InvoiceContext.php
class InvoiceContext implements Context
{
    /**
     * @Given I am logged in as an admin
     */
    public function iAmLoggedInAsAnAdmin(): void
    {
        // Implementation
    }

    /**
     * @When I create an invoice with amount :amount
     */
    public function iCreateAnInvoiceWithAmount(string $amount): void
    {
        // Implementation
    }

    /**
     * @Then the invoice should be saved successfully
     */
    public function theInvoiceShouldBeSavedSuccessfully(): void
    {
        // Assertions
    }
}
```

**Best Practices:**

* Keep step definitions focused and reusable
* Use dependency injection for services
* Avoid logic in step definitions - delegate to helper methods
* Make assertions clear and specific
* Handle cleanup in `@AfterScenario` hooks

### Test Types to Consider

Depending on the feature, you may need:

* **Feature Tests** - End-to-end scenario verification
* **Integration Tests** - Component interaction testing
* **Unit Tests** - Individual class/method testing

The specs primarily drive feature tests, but unit tests should be added for complex logic identified in the plan.

### Running Tests

Before proceeding, verify tests are:
* Syntactically correct
* Properly registered with the test framework
* Failing for the right reasons (feature not yet implemented)

```bash
# Example commands (adjust to project)
./vendor/bin/behat --dry-run    # Verify step definitions match
./vendor/bin/behat              # Run tests (should fail - TDD)
```

### Update specs.md

Add test implementation details to `.ngramx/tickets/[ticket-id]/specs.md`:

```markdown
# Specs for CORE-123

## Feature Files

[Already documented...]

## Test Implementation

### Context Classes Created
- `tests/features/InvoiceContext.php` - Invoice creation steps
- `tests/features/NotificationContext.php` - Notification verification

### Shared Steps Used
- `AuthenticationContext::iAmLoggedInAs()` - Existing auth steps

### Test Fixtures
- `tests/features/fixtures/valid-invoice.json`
```

### Approval Checkpoint

**STOP HERE and wait for user approval.**

Present the test implementation and ask the user to review:

* Do step definitions match the spec language?
* Is the test organization appropriate?
* Are assertions thorough enough?
* Any edge cases missing from tests?

Do not proceed to the Code step until the user approves the tests.

