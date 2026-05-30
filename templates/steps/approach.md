## Shared Step: Approach

Present architectural approaches for the user to choose from before detailed planning.

### Purpose

Before diving into detailed implementation planning, step back and consider different ways to solve the problem. This ensures:

* The user has visibility into architectural decisions
* Trade-offs are explicitly considered
* The best approach is chosen before investing in detailed planning

### What To Do

1. **Analyze the Problem Space**
   * Review the approved specs
   * Consider the existing codebase architecture
   * Identify constraints and dependencies

2. **Develop 2-3 Distinct Approaches**
   * Each approach should be meaningfully different
   * Consider varying dimensions: complexity, performance, maintainability, extensibility
   * Don't just present good/better/best versions of the same idea

3. **Present Each Approach With:**
   * A clear, descriptive name
   * High-level description (2-3 sentences)
   * Key architectural decisions it embodies
   * Pros and cons
   * Rough effort estimate (if relevant)

4. **Get User Selection**
   * Present approaches clearly
   * Answer any clarifying questions
   * Let the user choose

5. **Document the Chosen Approach**
   * Write a high-level summary to the top of `plan.md`
   * Include the rationale for why this approach was chosen

### Approach Template

When presenting approaches, use this structure:

```markdown
## Approach Options

### Option A: [Descriptive Name]

[2-3 sentence high-level description]

**Key Decisions:**
- [Architectural decision 1]
- [Architectural decision 2]

**Pros:**
- [Advantage 1]
- [Advantage 2]

**Cons:**
- [Disadvantage 1]
- [Disadvantage 2]

---

### Option B: [Descriptive Name]

[Similar structure...]

---

### Option C: [Descriptive Name] (if applicable)

[Similar structure...]
```

### Example Approaches

For a "user notification system" feature, you might present:

**Option A: Event-Driven with Queue**
- Publish events to a message queue, consumed by notification workers
- Pros: Decoupled, scalable, reliable delivery
- Cons: More infrastructure, eventual consistency

**Option B: Synchronous In-Process**
- Send notifications directly in the request cycle
- Pros: Simple, immediate, no new infrastructure
- Cons: Slower requests, less reliable, harder to scale

**Option C: Hybrid with Fallback**
- Try sync first, queue failures for retry
- Pros: Fast happy path, reliable delivery
- Cons: More complex logic, two code paths

### Updating plan.md

Once the user selects an approach, update `.ngramx/tickets/[ticket-id]/plan.md`:

```markdown
# Implementation Plan for CORE-123

## Chosen Approach: [Approach Name]

[High-level description of the approach - 1-2 paragraphs]

### Key Architectural Decisions

- [Decision 1 and brief rationale]
- [Decision 2 and brief rationale]

### Why This Approach

[Brief explanation of why this was chosen over alternatives]

---

## Detailed Plan

[This section will be filled in during the Planning step]
```

### Approval Checkpoint

**STOP HERE and wait for user to select an approach.**

Present the options clearly and let the user choose. They may:

* Select one of the approaches
* Ask clarifying questions
* Request modifications to an approach
* Ask for additional options

Once they've selected, document the choice in `plan.md` and confirm before proceeding to detailed planning.

