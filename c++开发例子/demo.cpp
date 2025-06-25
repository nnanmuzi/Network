#include <windows.h>
#include <wininet.h>
#include <string>
#include <sstream>
#include <ctime>
#include <vector>
#include <iostream>
#include <algorithm>
#include <thread>

#pragma comment(lib, "wininet.lib")

using namespace std;

// 配置信息
const string API_URL = "http://ipv6.xn--i8s98y.cn/Network/api.php";
const string APP_ID = "app_a0df33d4";
const string USER_ID = "UUTLZVLMGIC5B06CISVW9MFS";

// 全局变量
vector<string> debugLogs;
bool g_running = true;
string g_lastKami;
string g_lastMachineCode;
string g_lastIP;
HANDLE g_checkThread = INVALID_HANDLE_VALUE;

// ==================== 工具函数 ====================
string getTimestamp() {
    char buf[64];
    time_t now = time(0);
    struct tm tstruct;
    localtime_s(&tstruct, &now);
    strftime(buf, sizeof(buf), "%Y-%m-%d %X", &tstruct);
    return buf;
}

void showMessage(const string& title, const string& message, bool isError = false) {
    MessageBoxA(NULL, message.c_str(), title.c_str(), isError ? MB_ICONERROR : MB_ICONINFORMATION);
}

void addDebugLog(const string& message) {
    string logEntry = "[" + getTimestamp() + "] " + message;
    debugLogs.push_back(logEntry);
}

string timestampToBeijingTime(time_t timestamp) {
    if (timestamp == 0) return "永久有效";
    timestamp += 8 * 3600;
    struct tm tm;
    gmtime_s(&tm, &timestamp);
    char buf[64];
    strftime(buf, sizeof(buf), "%Y年%m月%d日 %H时%M分%S秒", &tm);
    return string(buf);
}

// ==================== 核心功能 ====================
string getJsonValue(const string& json, const string& key) {
    try {
        string pattern = "\"" + key + "\":";
        size_t start = json.find(pattern);
        if (start == string::npos) return "";

        start += pattern.length();
        while (start < json.size() && (json[start] == ' ' || json[start] == '"')) start++;

        size_t end = start;
        while (end < json.size() && json[end] != ',' && json[end] != '"' && json[end] != '}' && json[end] != ' ') end++;

        if (start >= json.size() || end > json.size() || start >= end) return "";

        string value = json.substr(start, end - start);
        value.erase(remove(value.begin(), value.end(), '"'), value.end());
        value.erase(remove(value.begin(), value.end(), '\r'), value.end());
        value.erase(remove(value.begin(), value.end(), '\n'), value.end());
        return value;
    }
    catch (const exception& e) {
        addDebugLog("JSON解析错误: " + string(e.what()) + " key: " + key);
        return "";
    }
}

string getCDriveSerial() {
    char volumeName[MAX_PATH + 1] = { 0 };
    char fileSystemName[MAX_PATH + 1] = { 0 };
    DWORD serialNumber = 0;
    DWORD maxComponentLen = 0;
    DWORD fileSystemFlags = 0;

    if (GetVolumeInformationA("C:\\", volumeName, ARRAYSIZE(volumeName),
        &serialNumber, &maxComponentLen, &fileSystemFlags,
        fileSystemName, ARRAYSIZE(fileSystemName))) {
        stringstream ss;
        ss << hex << serialNumber;
        addDebugLog("获取硬盘序列号成功: " + ss.str());
        return ss.str();
    }

    DWORD error = GetLastError();
    addDebugLog("获取硬盘序列号失败，错误代码: " + to_string(error));
    return "";
}

string secondsToDHMS(int seconds) {
    int days = seconds / 86400;
    seconds %= 86400;
    int hours = seconds / 3600;
    seconds %= 3600;
    int minutes = seconds / 60;
    seconds %= 60;

    stringstream ss;
    if (days > 0) ss << days << "天";
    if (hours > 0 || days > 0) ss << hours << "小时";
    if (minutes > 0 || hours > 0 || days > 0) ss << minutes << "分";
    ss << seconds << "秒";
    return ss.str();
}

string getPublicIP() {
    addDebugLog("开始获取公网IP地址...");
    HINTERNET hSession = InternetOpenA("IP Checker", INTERNET_OPEN_TYPE_PRECONFIG, NULL, NULL, 0);
    if (!hSession) {
        addDebugLog("InternetOpenA 初始化失败");
        return "";
    }

    HINTERNET hConnect = InternetOpenUrlA(hSession, "https://ipinfo.io/ip", NULL, 0,
        INTERNET_FLAG_RELOAD | INTERNET_FLAG_SECURE, 0);
    if (!hConnect) {
        addDebugLog("InternetOpenUrlA 连接失败");
        InternetCloseHandle(hSession);
        return "";
    }

    char buffer[1024] = { 0 };
    DWORD bytesRead = 0;
    string response;
    while (InternetReadFile(hConnect, buffer, sizeof(buffer) - 1, &bytesRead) && bytesRead) {
        buffer[bytesRead] = 0;
        response += buffer;
    }

    InternetCloseHandle(hConnect);
    InternetCloseHandle(hSession);

    response.erase(remove(response.begin(), response.end(), '\r'), response.end());
    response.erase(remove(response.begin(), response.end(), '\n'), response.end());
    response.erase(remove(response.begin(), response.end(), ' '), response.end());

    if (!response.empty()) addDebugLog("获取公网IP成功: " + response);
    else addDebugLog("获取公网IP失败");
    return response;
}

pair<string, DWORD> sendPostRequest(const string& jsonData) {
    addDebugLog("准备发送验证请求...");
    addDebugLog("请求内容: " + jsonData);

    HINTERNET hSession = InternetOpenA("KamiValidator", INTERNET_OPEN_TYPE_PRECONFIG, NULL, NULL, 0);
    if (!hSession) {
        addDebugLog("InternetOpenA 初始化失败");
        return { "网络初始化失败", 0 };
    }

    URL_COMPONENTSA urlComp = { 0 };
    char host[256] = { 0 }, path[256] = { 0 };
    urlComp.dwStructSize = sizeof(urlComp);
    urlComp.lpszHostName = host;
    urlComp.dwHostNameLength = sizeof(host);
    urlComp.lpszUrlPath = path;
    urlComp.dwUrlPathLength = sizeof(path);

    if (!InternetCrackUrlA(API_URL.c_str(), 0, 0, &urlComp)) {
        addDebugLog("InternetCrackUrlA 失败");
        InternetCloseHandle(hSession);
        return { "URL解析失败", 0 };
    }

    HINTERNET hConnect = InternetConnectA(hSession, host, urlComp.nPort, NULL, NULL, INTERNET_SERVICE_HTTP, 0, 0);
    if (!hConnect) {
        addDebugLog("InternetConnectA 失败");
        InternetCloseHandle(hSession);
        return { "连接服务器失败", 0 };
    }

    const char* acceptTypes[] = { "application/json", NULL };
    HINTERNET hRequest = HttpOpenRequestA(hConnect, "POST", path, NULL, NULL, acceptTypes,
        INTERNET_FLAG_RELOAD | INTERNET_FLAG_NO_CACHE_WRITE, 0);
    if (!hRequest) {
        addDebugLog("HttpOpenRequestA 失败");
        InternetCloseHandle(hConnect);
        InternetCloseHandle(hSession);
        return { "请求创建失败", 0 };
    }

    const char* headers = "Content-Type: application/json\r\n";
    if (!HttpSendRequestA(hRequest, headers, strlen(headers), (LPVOID)jsonData.c_str(), (DWORD)jsonData.length())) {
        DWORD error = GetLastError();
        addDebugLog("HttpSendRequestA 失败，错误代码: " + to_string(error));
        InternetCloseHandle(hRequest);
        InternetCloseHandle(hConnect);
        InternetCloseHandle(hSession);
        return { "发送请求失败", 0 };
    }

    DWORD statusCode = 0, length = sizeof(DWORD);
    if (!HttpQueryInfoA(hRequest, HTTP_QUERY_STATUS_CODE | HTTP_QUERY_FLAG_NUMBER, &statusCode, &length, NULL)) {
        addDebugLog("警告: HttpQueryInfoA 失败");
    }

    char buffer[4096] = { 0 };
    DWORD bytesRead = 0;
    string response;
    while (InternetReadFile(hRequest, buffer, sizeof(buffer) - 1, &bytesRead) && bytesRead) {
        buffer[bytesRead] = 0;
        response += buffer;
    }

    InternetCloseHandle(hRequest);
    InternetCloseHandle(hConnect);
    InternetCloseHandle(hSession);

    addDebugLog("服务器响应状态码: " + to_string(statusCode));
    addDebugLog("服务器响应内容: " + response);
    return { response, statusCode };
}

DWORD WINAPI CheckKamiStatus(LPVOID lpParam) {
    while (g_running) {
        Sleep(15000);

        time_t now = time(0);
        string json = "{"
            "\"ip\":\"" + g_lastIP + "\","
            "\"machine_code\":\"" + g_lastMachineCode + "\","
            "\"kami\":\"" + g_lastKami + "\","
            "\"uid\":\"" + USER_ID + "\","
            "\"appid\":\"" + APP_ID + "\","
            "\"timestamp\":" + to_string(now) +
            "}";

        auto result = sendPostRequest(json);
        string response = result.first;
        DWORD httpStatus = result.second;

        string code = getJsonValue(response, "code");
        string timeLeft = getJsonValue(response, "time_left");
        string status = getJsonValue(response, "status");
        string message = getJsonValue(response, "message");

        if (httpStatus == 200 && code == "100") {
            int seconds = 0;
            if (!timeLeft.empty()) {
                try { seconds = stoi(timeLeft); }
                catch (...) { seconds = 0; }
            }

            if ((seconds <= 0 && seconds != 3) || status == "expired" || message == "已过期") {
                showMessage("提示", "卡密已过期", true);
                Sleep(5000);
                ExitProcess(0);
            }
        }
    }
    return 0;
}

void validateKami(const string& kami) {
    if (kami.empty()) {
        showMessage("提示", "请输入卡密", true);
        return;
    }

    addDebugLog("开始验证卡密: " + kami);

    string ip = getPublicIP();
    if (ip.empty()) {
        showMessage("提示", "网络连接异常，请检查网络", true);
        return;
    }

    string machineCode = getCDriveSerial();
    if (machineCode.empty()) {
        showMessage("提示", "无法获取设备信息", true);
        return;
    }

    time_t now = time(0);
    string json = "{"
        "\"ip\":\"" + ip + "\","
        "\"machine_code\":\"" + machineCode + "\","
        "\"kami\":\"" + kami + "\","
        "\"uid\":\"" + USER_ID + "\","
        "\"appid\":\"" + APP_ID + "\","
        "\"timestamp\":" + to_string(now) +
        "}";

    auto result = sendPostRequest(json);
    string response = result.first;
    DWORD httpStatus = result.second;

    string code = getJsonValue(response, "code");
    string message = getJsonValue(response, "message");
    string timeLeft = getJsonValue(response, "time_left");
    string status = getJsonValue(response, "status");
    string createTime = getJsonValue(response, "create_time");
    string expireTime = getJsonValue(response, "expire_time");
    string bindStatus = getJsonValue(response, "bind_status");

    // 转换时间数据
    int seconds = 0;
    if (!timeLeft.empty()) {
        try { seconds = stoi(timeLeft); }
        catch (const exception& e) {
            addDebugLog("转换 time_left 时异常: " + string(e.what()));
            seconds = 0;
        }
    }

    time_t expireTimestamp = 0;
    if (!expireTime.empty()) {
        try { expireTimestamp = stol(expireTime); }
        catch (const exception& e) {
            addDebugLog("转换 expire_time 时异常: " + string(e.what()));
            expireTimestamp = 0;
        }
    }

    // 优先处理特定错误码
    if (code == "300") {
        showMessage("错误", "设备机器码不一致", true);
        return;
    }
    else if (code == "250") {
        showMessage("错误", "请求频繁已被封锁", true);
        return;
    }
    // 然后检查过期条件
    else if ((seconds <= 0 && seconds != 3) || code == "150" || status == "expired" || message == "已过期") {
        string expireMsg = "卡密已过期\n\n有效期至: " + timestampToBeijingTime(expireTimestamp);
        showMessage("提示", expireMsg, true);
        return;
    }
    // 最后处理成功情况
    else if (httpStatus == 200 && code == "100") {
        if (timeLeft.empty() || expireTime.empty()) {
            showMessage("错误", "验证数据不完整", true);
            return;
        }

        string timeStr = (seconds == 3) ? "永久有效" : secondsToDHMS(seconds);
        if (seconds != 3 && seconds <= 0) {
            showMessage("提示", "卡密已过期", true);
            return;
        }

        if (seconds != 3) {
            time_t currentTime = time(nullptr);
            if (expireTimestamp < currentTime) {
                showMessage("提示", "卡密已过期", true);
                return;
            }
        }

        g_lastKami = kami;
        g_lastMachineCode = machineCode;
        g_lastIP = ip;

        if (g_checkThread == INVALID_HANDLE_VALUE) {
            g_running = true;
            g_checkThread = CreateThread(NULL, 0, CheckKamiStatus, NULL, 0, NULL);
            if (!g_checkThread) addDebugLog("创建后台检查线程失败");
        }

        string bindStatusText = (bindStatus == "未绑定" || bindStatus.empty()) ? "已绑定" : bindStatus;
        string successMsg = "验证成功\n\n剩余时间: " + timeStr +
            "\n有效期至: " + timestampToBeijingTime(expireTimestamp) +
            "\n设备状态: " + bindStatusText;
        showMessage("提示", successMsg);
        //这里写接下来要执行的操作--开始

        //这里写接下来要执行的操作--结束
    }
    else {
        string failMsg = "验证失败";
        if (code == "200") failMsg = "卡密无效";
        else if (code == "400") failMsg = "请求参数错误";
        else if (code == "401") failMsg = "系统时间异常";
        else if (code == "402") failMsg = "用户ID错误";
        else if (code == "403") failMsg = "应用ID错误";
        else if (code == "408") failMsg = "appid和uid错误";

        showMessage("提示", failMsg, true);
    }
}

void showAllLogs() {
    cout << "\n=== 运行日志 ===" << endl;
    for (const auto& log : debugLogs) {
        cout << log << endl;
    }
    cout << "================" << endl;
}

int main() {
    cout << "=== demo Muzi v2.1.1 ===" << endl;
    cout << "输入卡密进行验证（输入'end'退出）\n" << endl;
    cout << "第一次验证会稍慢，因为需要和服务器对齐数据\n" << endl;
    while (true) {
        cout << "请输入: ";
        string input;
        getline(cin, input);

        if (input == "end") {
            g_running = false;
            if (g_checkThread != INVALID_HANDLE_VALUE) {
                WaitForSingleObject(g_checkThread, 1000);
                CloseHandle(g_checkThread);
            }
            break;
        }
        else if (input == "muzi-log") {
            showAllLogs();
        }
        else {
            validateKami(input);
        }
    }
    return 0;
}
