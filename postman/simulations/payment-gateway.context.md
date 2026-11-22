# Payment gateway simulation

See `payment-gateway.context.yaml` for assumptions and evidence. The mock is based on the user-provided form fields and response shape with synthetic values. It does not establish the full live gateway contract or the actual callback Content-Type.

Run locally:

```sh
postman simulation run postman/simulations/payment-gateway.sim.yaml --port 40291 --no-history --output ndjson
PAYMENT_SIM_URL=http://127.0.0.1:40291/payment/api/generateLink PAYMENT_SIM_UNREACHABLE_URL=http://127.0.0.1:40292/payment/api/generateLink php artisan test --filter=PaymentTest
```

The unreachable-port test assumes no service is listening on 40292. Simulation traffic was observed on `POST /api/generateLink` with HTTP 200; the test also checked an unreachable local port and retained the attempt as `creation_unknown`. The Postman owner mock search and webhook capture were unavailable without authentication. No live payment request was made.
