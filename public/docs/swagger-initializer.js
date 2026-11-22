/* HttpOnly session cookies are established by the API, not entered into Swagger's Authorize dialog. */
window.ui = SwaggerUIBundle({
  url: './openapi.json',
  dom_id: '#swagger-ui',
  deepLinking: true,
  validatorUrl: null,
  withCredentials: true,
  persistAuthorization: false,
  requestInterceptor: (request) => {
    request.credentials = 'include';
    const token = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
    if (token) request.headers['X-XSRF-TOKEN'] = decodeURIComponent(token.substring('XSRF-TOKEN='.length));
    return request;
  }
});
