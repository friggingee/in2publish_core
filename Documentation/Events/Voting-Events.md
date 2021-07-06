# Voting Events

Voting is a vital part in the content publisher. It enables 3rd party code to change the content publisher's behavior
from the outside without touching the code at all.

## Exceptions

[`VoteIfRecordIsPublishable` (link)](VoteIfRecordIsPublishable.md) is an exception to these rules. Please refer to the
documentation of that event for more information.

## Voting Rules

How are votes evaluated?

* The sum of all votes will be the result.
* The voting result will be `true` when there are more "Yes" than "No" votes.
* If more event listeners voted for "No" or if the voting is a draw, the voting result will be `false`.
