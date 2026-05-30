## Shared Step: Planning

Create a detailed implementation plan with classes, contracts, and pseudocode.

### Purpose

Expand the chosen approach into a comprehensive implementation plan that:

* Documents all classes and their contracts
* Includes pseudocode for complex logic
* Lists all files to be created or modified
* Serves as a blueprint for implementation

### Prerequisites

Before this step:
* Specs must be approved
* Approach must be selected and documented in `plan.md`

### What To Do

1. **Design the Class Structure**
   * Identify all classes/modules needed
   * Define their responsibilities (single responsibility principle)
   * Document relationships between classes

2. **Define Contracts/Interfaces**
   * Public methods with signatures
   * Expected inputs and outputs
   * Dependencies and injections

3. **Write Pseudocode**
   * Cover complex algorithms or business logic
   * Focus on the "how" at a conceptual level
   * Keep it language-agnostic where possible

4. **List File Changes**
   * New files to create
   * Existing files to modify
   * Files to delete (if any)

5. **Iterate Based on Feedback**
   * Present the plan to the user
   * Adjust naming, structure based on feedback
   * Refine until user is satisfied

### Plan Structure

Update `.ngramx/tickets/[ticket-id]/plan.md` with the detailed plan:

```markdown
# Implementation Plan for CORE-123

## Chosen Approach: [Approach Name]

[Already filled in from Approach step]

---

## Detailed Plan

### Classes and Contracts

#### [ClassName]

**Responsibility:** [Single sentence describing what this class does]

**Location:** `src/path/to/ClassName.php`

**Dependencies:**
- `DependencyOne` - [why needed]
- `DependencyTwo` - [why needed]

**Public Interface:**

```
method doSomething(param: Type): ReturnType
  - [Brief description of what it does]
  - Throws: ExceptionType when [condition]

method anotherMethod(param: Type): ReturnType
  - [Brief description]
```

---

#### [AnotherClass]

[Same structure...]

---

### Pseudocode

#### [Complex Operation Name]

```
function complexOperation(input):
    validate input
    
    for each item in input:
        process item
        if condition:
            handle special case
        else:
            normal processing
    
    aggregate results
    return formatted output
```

---

### File Changes

#### New Files
- `src/Services/NewService.php` - [purpose]
- `src/Models/NewModel.php` - [purpose]
- `tests/Feature/NewFeatureTest.php` - [purpose]

#### Modified Files
- `src/Providers/AppServiceProvider.php` - Register new service
- `routes/api.php` - Add new endpoints

#### Deleted Files
- None

---

### Database Changes (if applicable)

#### New Tables
- `table_name` - [purpose and key columns]

#### Migrations
- `create_table_name_table` - [what it does]

---

### Notes

[Any additional technical notes, gotchas, or considerations]
```

### Naming Conventions

When defining classes and methods:
* Follow the project's existing naming conventions
* Be descriptive but concise
* Use domain language from the specs
* Consider how names will read in code

### Approval Checkpoint

**STOP HERE and wait for user approval.**

Present the detailed plan and ask the user to review:

* Are the class names and structures appropriate?
* Does the organization make sense?
* Any concerns about the approach at this level?
* Feedback on method names and signatures?

Iterate on the plan based on feedback. The user may request:
* Renaming classes or methods
* Restructuring responsibilities
* Adding or removing components
* Clarifying pseudocode

Do not proceed to the Tests step until the user approves the final plan.

