from django.http import HttpResponse


class HtmxRedirectMiddleware:
    """
    A redirect answered to an htmx request (session expired → login, the temporary-password
    gate, a view that still redirects) would be followed by XHR and its page swapped in as a
    fragment. Hand it to htmx as a client-side redirect so the browser loads it normally.
    """

    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        response = self.get_response(request)
        if request.headers.get("HX-Request") == "true" and 300 <= response.status_code < 400 and response.has_header("Location"):
            redirect = HttpResponse(status=200)  # htmx acts on the header; the body is ignored
            redirect["HX-Redirect"] = response["Location"]
            return redirect
        return response
