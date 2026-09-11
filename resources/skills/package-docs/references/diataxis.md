# Diátaxis System Guide

Documentation in this ecosystem is structured using the **Diátaxis** framework. Diátaxis distinguishes four communication needs based on two orthogonal axes:
- **Focus**: Learning-oriented vs Information-oriented
- **Activity**: Practical (action) vs Theoretical (thought)

```
                    LEARNING-ORIENTED
                           │
            Tutorials      │    How-to Guides
         (First success)   │   (Solve a problem)
                           │
  ─────────────────────────┼─────────────────────────
  PRACTICAL                │              THEORETICAL
                           │
           Reference       │     Explanation
       (Information truth) │    (Understanding)
                           │
                    INFORMATION-ORIENTED
```

---

## 1. Tutorials (Learning-Oriented / Practical)

- **Purpose**: Get a beginner to their first working result with minimal cognitive friction.
- **Mental State**: The user is learning and lacks a complete mental model. Do not explain architecture yet.
- **Rules**:
  - Show concrete, linear steps: Do A, then run B, see output C.
  - No branching choices or speculative tangents.
  - In Tier 1 & 2 packages, the Tutorial is the **Quickstart** section in the `README.md`.
  - Must take less than 60 seconds to execute.

---

## 2. How-to Guides (Problem-Oriented / Practical)

- **Purpose**: Guide a competent user to solve a specific real-world problem or workflow.
- **Mental State**: The user already knows what the package does; they want to accomplish task X (e.g., "How to customize the audit manifest path").
- **Rules**:
  - Focus on a concrete outcome.
  - Can include options and trade-offs.
  - Does NOT explain foundational concepts (link to Explanation instead).
  - Code examples must be copy-paste ready.

---

## 3. Reference (Information-Oriented / Theoretical Facts)

- **Purpose**: Authoritative, complete, dry description of the machinery.
- **Mental State**: The user is actively coding and needs exact specifications (method signatures, parameters, return types, configuration keys, console flags).
- **Rules**:
  - Structure follows the code architecture (classes, config files, CLI commands).
  - Exhaustive completeness over brevity: do not skip optional parameters.
  - Tone is neutral, descriptive, and unambiguous.
  - Never contain storytelling or tutorials.

---

## 4. Explanation (Understanding-Oriented / Theoretical Concepts)

- **Purpose**: Clarify why the software is designed this way, architectural decisions, and trade-offs.
- **Mental State**: The user is thinking, evaluating alternatives, or debugging fundamental assumptions.
- **Rules**:
  - Discuss the problem space, historical context, and architectural invariants.
  - Contrast with alternative approaches.
  - In the `README.md`, this is represented by the **Why This Exists** and **Architecture / Concepts** sections.