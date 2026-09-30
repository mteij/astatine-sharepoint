# Project Context: S.A. Astatine Committee Portal (Phase 1)

Act as an expert PHP and CakePHP developer. We are building a Proof of Concept (PoC) portal for a student association (S.A. Astatine) to simplify access to their committee files stored in Microsoft Teams (Private Channels).

Currently, students get lost in SharePoint. Our goal is to build a CakePHP application hosted on `webapps.astatine.utwente.nl` that acts as a smart, authenticated routing dashboard.

## Architecture & Flow

1. **Authentication:** User logs in using their university account (`@student.utwente.nl`) via Microsoft Entra ID (OAuth 2.0).
2. **Data Fetching:** Upon successful login, the app uses the Access Token to call the Microsoft Graph API (`/me/memberOf`) to check which Microsoft Teams/Groups the user belongs to (e.g., "KasCo", "ATAC").
3. **Rendering:** The CakePHP View displays a dashboard with visual tiles/buttons ONLY for the committees the user is a member of.
4. **Deep Linking (Handoff):** These buttons link directly to the underlying SharePoint URL of that specific Teams Private Channel.

## Tech Stack

* Framework: CakePHP (assume latest stable version)
* Auth/OAuth2: `league/oauth2-client` and `league/oauth2-google` (or generic Microsoft provider like `stevenmaguire/oauth2-microsoft`)
* API: Microsoft Graph API v1.0

## Step-by-Step Implementation Plan for the Agent

Please generate the necessary code, file by file, for the following steps:

### Step 1: Composer & Dependencies

Provide the composer command to install the required OAuth2 client library for Microsoft Entra ID.

### Step 2: Configuration

Generate the configuration arrays needed in `config/app_local.php` (or a custom config file) to store the Entra ID credentials: `CLIENT_ID`, `CLIENT_SECRET`, `TENANT_ID`, and `REDIRECT_URI`.

### Step 3: The Authentication Component / Controller Logic

Create a `CommitteesController.php` (and optionally an AuthComponent if best practice dictates) with the following actions:

* `login()`: Redirects the user to the Microsoft Entra ID login page.
* `callback()`: Handles the return URL, exchanges the authorization code for an Access Token, and stores it in the session.
* `logout()`: Clears the session.

### Step 4: The Graph API Service

Write a private method or a separate Service class that uses the stored Access Token to make a GET request to `https://graph.microsoft.com/v1.0/me/memberOf`.

* Parse the JSON response.
* Extract the names or IDs of the groups the user is a member of.
* Return this as a clean PHP array.

### Step 5: The Dashboard Action & View

In the `CommitteesController::index()` action:

* Check if the user is authenticated. If not, render a public landing page with a "Login with UTwente" button and a link to request access.
* If authenticated, call the Graph API service to get the user's groups.
* Pass the array of allowed groups to the View.

Create the corresponding template `templates/Committees/index.php`:

* Write clean HTML/CSS (using a simple modern grid layout).
* Use PHP `if` statements to check if the user has access to a specific group (e.g., `in_array('KasCo', $userGroups)`).
* If true, render a tile for that committee with a hardcoded `href` pointing to their SharePoint folder.

## Future Scope (Do not build now, but keep architecture clean for this)

In Phase 2, we will replace the hardcoded outbound links with an embedded File Explorer built natively in CakePHP by querying the Graph API for folder contents (`/drives/{drive-id}/root/children`). Keep the Graph API service modular so we can easily add endpoints later.

---

Please start by confirming you understand the architecture, and then provide the Composer commands and the `CommitteesController.php` code.
