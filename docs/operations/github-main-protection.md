# GitHub `main` protection

Owner: repository administrator  
Last verified: 2026-08-26  
Source ticket: [#3016](https://github.com/malipie/PIM/issues/3016)

`main` is protected through the GitHub branch-protection API. The committed
request snapshot is [`github-main-protection.json`](github-main-protection.json).
It is the reviewable source of the intended settings; the GitHub API remains
the runtime source of truth.

## Enforced contract

- pull requests are mandatory and must be up to date with `main`;
- one approving review is required and stale approvals are dismissed;
- all configured PHP, frontend, security and dependency checks must pass;
- unresolved review conversations block merge;
- force-push, branch deletion and non-linear history are forbidden.

Workflow-level PR path filters must not be added to a workflow that emits a
required check. If the workflow does not start, GitHub leaves its context in
`Expected` forever. Expensive work may be skipped at job level because a
skipped job still emits a terminal check result.

## Verify or restore

```bash
gh api repos/malipie/PIM/branches/main/protection

gh api \
  --method PUT \
  repos/malipie/PIM/branches/main/protection \
  --input docs/operations/github-main-protection.json
```

After any edit, open a probe PR and verify both negative cases:

1. a failing required check produces `mergeStateStatus=BLOCKED`;
2. after the check is green, a missing approval still produces
   `mergeStateStatus=BLOCKED`.

Record the probe PR, head SHA and API output in the related issue. Close the
probe without merging if it exists only to test the policy.

## Break-glass

The repository currently has one administrator. Requiring an independent
approval would otherwise make maintenance impossible, so GitHub administrators
are not themselves bound by the protection rule. This is a recovery path, not
the normal merge path.

An administrator may bypass only when all of the following are true:

1. the change has a ticket and a pull request;
2. every emitted check is terminal and green (`success`, `neutral` or
   intentional `skipped`); no check is pending;
3. the PR is up to date and has no unresolved conversation;
4. the PR comment states why independent approval was unavailable and links
   the command or incident that required the bypass;
5. merge uses `gh pr merge --squash --admin`; direct pushes remain forbidden by
   project policy even though an administrator could technically perform one.

The PR timeline and repository audit log are the audit trail. A bypass with a
red or pending check is a policy violation and requires a follow-up incident
ticket.
