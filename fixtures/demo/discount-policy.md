# Discount policy

> Demo document. Fictional content written to show the tool.

## Context

Acme Shop sells outdoor gear online. Marketing runs promo codes, and loyal customers get a bonus on top. Both must stay under control: a discount that runs away costs margin on every order.

## Discount cap

The total discount on an order never exceeds **10 %** of the order subtotal, whatever the combination of promo code and loyalty bonus.

The cap is a single setting owned by marketing. Changing it must not need a new release of the pricing code.

## One promo code per order

A customer can apply **one promo code per order**. Entering a second code replaces the first one; the two are never added up.

## Loyalty bonus

Gold customers get an extra **2 %** on every order. The bonus is added to the promo code, and the cap above applies to the sum.

Silver and bronze customers get no bonus for now.

## Open questions

- Does the cap apply before or after shipping costs?
- What happens to a promo code when the order is split into two shipments?
