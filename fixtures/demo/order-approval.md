# Order approval

> Demo document. Fictional content written to show the tool.

## Why

Business customers order on behalf of their company. Large orders have to be checked by a manager of that company before Acme Shop charges the card.

## Threshold

An order **over €500**, taxes included, waits for the approval of a manager of the customer's company. An order of exactly €500 goes through without approval.

The manager receives an email with a link to approve or decline the order.

## Expiry

An approval request expires after **48 hours**. An order still waiting at that point is cancelled and the customer is told by email. Nothing is charged.

## Who approves

Any user with the manager role in the customer's company can approve. A manager cannot approve their own order.
